import json
import os
import re
import threading
from datetime import date, timedelta

from flask import Flask, jsonify, request, send_from_directory

HERE = os.path.dirname(os.path.abspath(__file__))
DATA_FILE = os.path.join(HERE, 'data.json')
POINTS_BY_RANK = [9, 6, 4, 3, 2, 1]  # 1st..6th place, everyone else 0

app = Flask(__name__)
_lock = threading.Lock()


class ApiError(Exception):
    def __init__(self, msg):
        super().__init__(msg)
        self.msg = msg


# ---------- points / defaults / persistence ----------

def points_for(rank):
    if 1 <= rank <= len(POINTS_BY_RANK):
        return POINTS_BY_RANK[rank - 1]
    return 0


def defaults():
    return {
        'teams': [],     # {id, name}
        'players': [],   # {id, name, teamId, upgrades[]}
        'maps': [],      # [name]
        'matches': [],   # {id, map, date, results:[{playerId, score}]}
        'seq': {'team': 1, 'player': 1, 'match': 1},
    }


def load():
    d = defaults()
    if os.path.isfile(DATA_FILE):
        try:
            with open(DATA_FILE, 'r', encoding='utf-8') as f:
                j = json.load(f)
        except (ValueError, OSError):
            j = None
        if isinstance(j, dict):
            d = {**d, **j}
            d['seq'] = {**defaults()['seq'], **(j.get('seq') or {})}
    return d


def save(d):
    tmp = DATA_FILE + '.tmp'
    try:
        with open(tmp, 'w', encoding='utf-8') as f:
            json.dump(d, f, indent=2, ensure_ascii=False)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, DATA_FILE)
    except OSError:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise ApiError('Cannot write data file')


# ---------- input helpers ----------

def in_str(in_, key, max_len=60):
    v = in_.get(key)
    if not isinstance(v, str):
        return None
    v = v.strip()
    if v == '':
        return None
    return v[:max_len]


def in_int(in_, key, default=None):
    v = in_.get(key)
    if v is None or v == '':
        return default
    if isinstance(v, bool):
        return None
    if isinstance(v, (int, float)):
        return int(v)
    if isinstance(v, str):
        try:
            return int(v.strip())
        except ValueError:
            return None
    return None


def upgrades_in(in_):
    raw = in_.get('upgrades', [])
    if isinstance(raw, str):
        raw = re.split(r'[\s,]+', raw.strip()) if raw.strip() else []
    if not isinstance(raw, list):
        return []
    out = []
    for u in raw:
        u = str(u).strip()
        if re.fullmatch(r'[0-9]{1,4}', u):
            v = int(u)
            if v not in out:
                out.append(v)
            if len(out) >= 8:
                break
    out.sort()
    return out


def find_match(d, mid):
    for i, m in enumerate(d['matches']):
        if m['id'] == mid:
            return i
    raise ApiError('Match not found')


def match_locked(m):
    if m.get('locked'):
        return True
    if m.get('unlocked'):
        return False
    dt = m.get('date')
    if not isinstance(dt, str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}', dt):
        return False
    return dt < (date.today() - timedelta(days=1)).isoformat()


def require_unlocked(d, mid):
    i = find_match(d, mid)
    if match_locked(d['matches'][i]):
        raise ApiError('Match is locked')
    return i


def own_team(d, name):
    for t in d['teams']:
        if str(t.get('name', '')).lower() == name.lower():
            return t['id']
    tid = d['seq']['team']
    d['teams'].append({'id': tid, 'name': name})
    d['seq']['team'] += 1
    return tid


def _pos(r):
    v = r.get('position', 0)
    if isinstance(v, bool):
        return 0
    if isinstance(v, int):
        return v
    if isinstance(v, float):
        return int(v)
    if isinstance(v, str):
        try:
            return int(v.strip())
        except ValueError:
            return 0
    return 0


# ---------- leaderboard ----------

def leaderboard(d):
    ranked = {}
    match_pts = {}     # match id -> {player id: points}
    match_players = {}  # match id -> set of player ids
    for m in d['matches']:
        rows = [r for r in m.get('results', []) if r.get('playerId') is not None]
        mid = m['id']
        if not rows:
            ranked[mid] = []
            match_pts[mid] = {}
            match_players[mid] = set()
            continue
        placed = sorted((r for r in rows if _pos(r) >= 1), key=lambda r: (_pos(r), r['playerId']))
        unplaced = sorted((r for r in rows if _pos(r) < 1), key=lambda r: r['playerId'])
        out = []
        rank = 0
        prev_pos = None
        pts_in_match = {}
        for i, r in enumerate(placed):
            # competition ranking: equal positions share the rank
            pos = _pos(r)
            if prev_pos is None or pos != prev_pos:
                rank = i + 1
                prev_pos = pos
            row = {'playerId': r['playerId'], 'position': pos, 'rank': rank, 'points': points_for(rank)}
            out.append(row)
            pts_in_match[r['playerId']] = row['points']
        for r in unplaced:
            out.append({'playerId': r['playerId'], 'position': 0, 'rank': 0, 'points': 0})
        ranked[mid] = out
        match_pts[mid] = pts_in_match
        match_players[mid] = {r['playerId'] for r in rows}

    player_team = {}
    for p in d['players']:
        if p.get('teamId') is None:
            continue
        try:
            player_team[p['id']] = int(p['teamId'])
        except (TypeError, ValueError):
            continue

    teams = []
    for t in d['teams']:
        member_ids = {pid for pid, tid in player_team.items() if tid == t['id']}
        names = [p['name'] for p in d['players'] if p['id'] in member_ids]
        # per match, only the team's best-placed member counts
        contribs = []
        for mid in match_players:
            in_match = member_ids & match_players[mid]
            if in_match:
                contribs.append(max(match_pts[mid].get(pid, 0) for pid in in_match))
        # only the team's 7 best matches count
        contribs.sort(reverse=True)
        teams.append({'id': t['id'], 'name': t['name'], 'points': sum(contribs[:7]), 'players': names})
    teams.sort(key=lambda t: (-t['points'], str(t['name']).lower()))

    return {'teams': teams, 'ranked': ranked}


# ---------- actions ----------

def act_state(d, in_):
    lb = leaderboard(d)
    return {
        'teams': d['teams'],
        'players': d['players'],
        'maps': d['maps'],
        'matches': [{**m, 'locked': match_locked(m)} for m in d['matches']],
        'board': lb['teams'],
        'ranked': lb['ranked'],
    }


def act_team_add(d, in_):
    name = in_str(in_, 'name', 40)
    if name is None:
        raise ApiError('Team name required')
    for t in d['teams']:
        if t['name'].lower() == name.lower():
            raise ApiError('Team already exists')
    d['teams'].append({'id': d['seq']['team'], 'name': name})
    d['seq']['team'] += 1
    save(d)
    return {}


def act_team_rename(d, in_):
    tid = in_int(in_, 'id')
    name = in_str(in_, 'name', 40)
    if tid is None or name is None:
        raise ApiError('Bad input')
    for t in d['teams']:
        if t['id'] == tid:
            t['name'] = name
    save(d)
    return {}


def act_team_delete(d, in_):
    tid = in_int(in_, 'id')
    if tid is None:
        raise ApiError('Bad input')
    d['teams'] = [t for t in d['teams'] if t['id'] != tid]
    for p in d['players']:
        if p['teamId'] == tid:
            p['teamId'] = own_team(d, p['name'])
    save(d)
    return {}


def act_player_add(d, in_):
    name = in_str(in_, 'name', 40)
    if name is None:
        raise ApiError('Player name required')
    team_id = in_int(in_, 'teamId')
    team_ids = [t['id'] for t in d['teams']]
    if team_id is None or team_id not in team_ids:
        team_id = own_team(d, name)
    d['players'].append({
        'id': d['seq']['player'],
        'name': name,
        'teamId': team_id,
        'upgrades': upgrades_in(in_),
    })
    d['seq']['player'] += 1
    save(d)
    return {}


def act_player_update(d, in_):
    pid = in_int(in_, 'id')
    if pid is None:
        raise ApiError('Bad input')
    found = False
    team_ids = [t['id'] for t in d['teams']]
    for p in d['players']:
        if p['id'] != pid:
            continue
        found = True
        if 'name' in in_ and in_.get('name') is not None:
            n = in_str(in_, 'name', 40)
            if n is None:
                raise ApiError('Bad name')
            p['name'] = n
        if 'teamId' in in_:
            tid = in_int(in_, 'teamId')
            if tid is None or tid not in team_ids:
                p['teamId'] = own_team(d, p['name'])
            else:
                p['teamId'] = tid
        if 'upgrades' in in_ and in_.get('upgrades') is not None:
            p['upgrades'] = upgrades_in(in_)
    if not found:
        raise ApiError('Player not found')
    save(d)
    return {}


def act_player_delete(d, in_):
    pid = in_int(in_, 'id')
    if pid is None:
        raise ApiError('Bad input')
    d['players'] = [p for p in d['players'] if p['id'] != pid]
    for m in d['matches']:
        m['results'] = [r for r in m['results'] if r['playerId'] != pid]
    save(d)
    return {}


def act_map_add(d, in_):
    name = in_str(in_, 'name', 40)
    if name is None:
        raise ApiError('Map name required')
    for m in d['maps']:
        if m.lower() == name.lower():
            raise ApiError('Map already exists')
    d['maps'].append(name)
    save(d)
    return {}


def act_map_delete(d, in_):
    name = in_str(in_, 'name')
    if name is None:
        raise ApiError('Bad input')
    d['maps'] = [m for m in d['maps'] if m != name]
    save(d)
    return {}


def act_match_add(d, in_):
    m = in_str(in_, 'map', 40)
    dt = in_str(in_, 'date', 10)
    if m is None:
        raise ApiError('Map required')
    if dt is None or not re.fullmatch(r'\d{4}-\d{2}-\d{2}', dt):
        raise ApiError('Date required (YYYY-MM-DD)')
    if m not in d['maps']:
        d['maps'].append(m)
    d['matches'].append({
        'id': d['seq']['match'],
        'map': m,
        'date': dt,
        'results': [{'playerId': p['id'], 'position': 0} for p in d['players']],
    })
    d['seq']['match'] += 1
    save(d)
    return {}


def act_match_update(d, in_):
    mid = in_int(in_, 'id')
    if mid is None:
        raise ApiError('Bad input')
    i = require_unlocked(d, mid)
    m = d['matches'][i]
    if 'map' in in_ and in_.get('map') is not None:
        mname = in_str(in_, 'map', 40)
        if mname is not None:
            m['map'] = mname
            if mname not in d['maps']:
                d['maps'].append(mname)
    if 'date' in in_ and in_.get('date') is not None:
        dt = in_str(in_, 'date', 10)
        if dt is not None and re.fullmatch(r'\d{4}-\d{2}-\d{2}', dt):
            m['date'] = dt
    save(d)
    return {}


def act_match_delete(d, in_):
    mid = in_int(in_, 'id')
    if mid is None:
        raise ApiError('Bad input')
    i = find_match(d, mid)
    if match_locked(d['matches'][i]):
        raise ApiError('Match is locked')
    d['matches'] = [m for m in d['matches'] if m['id'] != mid]
    save(d)
    return {}


def act_match_add_player(d, in_):
    mid = in_int(in_, 'matchId')
    pid = in_int(in_, 'playerId')
    if mid is None or pid is None:
        raise ApiError('Bad input')
    if pid not in [p['id'] for p in d['players']]:
        raise ApiError('Player not found')
    i = require_unlocked(d, mid)
    results = d['matches'][i]['results']
    if pid not in [r['playerId'] for r in results]:
        pos = max(0, in_int(in_, 'position', 0))
        if pos >= 1 and any(_pos(r) == pos for r in results):
            raise ApiError('Position already taken')
        results.append({'playerId': pid, 'position': pos})
        save(d)
    return {}


def act_match_set_position(d, in_):
    mid = in_int(in_, 'matchId')
    pid = in_int(in_, 'playerId')
    pos = in_int(in_, 'position')
    if mid is None or pid is None or pos is None or pos < 0:
        raise ApiError('Bad input')
    i = require_unlocked(d, mid)
    results = d['matches'][i]['results']
    if pos >= 1 and any(r['playerId'] != pid and _pos(r) == pos for r in results):
        raise ApiError('Position already taken')
    for r in results:
        if r['playerId'] == pid:
            r['position'] = pos
    save(d)
    return {}


def act_match_remove_player(d, in_):
    mid = in_int(in_, 'matchId')
    pid = in_int(in_, 'playerId')
    if mid is None or pid is None:
        raise ApiError('Bad input')
    i = require_unlocked(d, mid)
    d['matches'][i]['results'] = [r for r in d['matches'][i]['results'] if r['playerId'] != pid]
    save(d)
    return {}


def act_match_unlock(d, in_):
    mid = in_int(in_, 'id')
    if mid is None:
        raise ApiError('Bad input')
    i = find_match(d, mid)
    m = d['matches'][i]
    m['unlocked'] = True
    m.pop('locked', None)
    save(d)
    return {}


def act_match_lock(d, in_):
    mid = in_int(in_, 'id')
    if mid is None:
        raise ApiError('Bad input')
    i = find_match(d, mid)
    m = d['matches'][i]
    m['locked'] = True
    m.pop('unlocked', None)
    save(d)
    return {}


def act_wipe(d, in_):
    save(defaults())
    return {}


HANDLERS = {
    'state': act_state,
    'team_add': act_team_add,
    'team_rename': act_team_rename,
    'team_delete': act_team_delete,
    'player_add': act_player_add,
    'player_update': act_player_update,
    'player_delete': act_player_delete,
    'map_add': act_map_add,
    'map_delete': act_map_delete,
    'match_add': act_match_add,
    'match_update': act_match_update,
    'match_delete': act_match_delete,
    'match_add_player': act_match_add_player,
    'match_set_position': act_match_set_position,
    'match_remove_player': act_match_remove_player,
    'match_unlock': act_match_unlock,
    'match_lock': act_match_lock,
    'wipe': act_wipe,
}


# ---------- routes ----------

def get_input():
    data = request.get_json(silent=True)
    if isinstance(data, dict):
        return data
    return request.form.to_dict()


def get_action(in_):
    action = request.args.get('action')
    if action is None:
        action = in_.get('action')
    return str(action) if action is not None else ''


@app.route('/')
def index():
    return send_from_directory(HERE, 'index.html')


@app.route('/api', methods=['GET', 'POST'])
def api():
    in_ = get_input()
    action = get_action(in_)
    with _lock:
        d = load()
        try:
            handler = HANDLERS.get(action)
            if handler is None:
                raise ApiError('Unknown action')
            extra = handler(d, in_) or {}
            return jsonify({'ok': True, **extra})
        except ApiError as e:
            return jsonify({'ok': False, 'error': e.msg}), 400


if __name__ == '__main__':
    app.run(host='127.0.0.1', port=8000)
