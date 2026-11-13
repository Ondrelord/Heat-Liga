<?php
declare(strict_types=1);

/**
 * Heat Liga — API (PHP port of app.py).
 * Entry point is index.php, which routes /api here.
 * Data is stored in SQLite (data.sqlite) via storage.php.
 */

require __DIR__ . '/storage.php';

const POINTS_BY_RANK = [9, 6, 4, 3, 2, 1]; // 1st..6th place, everyone else 0

class ApiError extends Exception
{
}

// ---------- points ----------

function points_for(int $rank): int
{
    if ($rank >= 1 && $rank <= count(POINTS_BY_RANK)) {
        return POINTS_BY_RANK[$rank - 1];
    }
    return 0;
}

// ---------- persistence (SQLite via storage.php) ----------

function load(): array
{
    try {
        return storage_load();
    } catch (Throwable $e) {
        throw new ApiError('Cannot read database');
    }
}

function save(array $d): void
{
    try {
        storage_save($d);
    } catch (Throwable $e) {
        throw new ApiError('Cannot write database');
    }
}

// ---------- input helpers ----------

function clip(string $v, int $maxLen): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($v, 0, $maxLen);
    }
    return substr($v, 0, $maxLen);
}

function in_str(?array $in, string $key, int $maxLen = 60): ?string
{
    $v = $in[$key] ?? null;
    if (!is_string($v)) {
        return null;
    }
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    return clip($v, $maxLen);
}

function in_int(?array $in, string $key, ?int $default = null): ?int
{
    if (!is_array($in) || !array_key_exists($key, $in)) {
        return $default;
    }
    $v = $in[$key];
    if ($v === null || $v === '') {
        return $default;
    }
    if (is_bool($v)) {
        return null;
    }
    if (is_int($v) || is_float($v)) {
        return (int) $v;
    }
    if (is_string($v)) {
        $t = trim($v);
        if (preg_match('/^[+-]?\d+$/', $t)) {
            return (int) $t;
        }
        return null;
    }
    return null;
}

function upgrades_in(?array $in): array
{
    $raw = $in['upgrades'] ?? [];
    if (is_string($raw)) {
        $raw = trim($raw) === '' ? [] : (preg_split('/[\s,]+/', trim($raw)) ?: []);
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $u) {
        $u = trim((string) $u);
        if (preg_match('/^\d{1,4}$/', $u)) {
            $v = (int) $u;
            if (!in_array($v, $out, true)) {
                $out[] = $v;
            }
            if (count($out) >= 8) {
                break;
            }
        }
    }
    sort($out);
    return $out;
}

function find_match(array $d, int $mid): int
{
    foreach ($d['matches'] as $i => $m) {
        if (($m['id'] ?? null) === $mid) {
            return $i;
        }
    }
    throw new ApiError('Match not found');
}

function match_locked(array $m): bool
{
    if (!empty($m['locked'])) {
        return true;
    }
    if (!empty($m['unlocked'])) {
        return false;
    }
    $dt = $m['date'] ?? null;
    if (!is_string($dt) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
        return false;
    }
    $yesterday = (new DateTime('today'))->modify('-1 day')->format('Y-m-d');
    return $dt < $yesterday;
}

function require_unlocked(array &$d, int $mid): int
{
    $i = find_match($d, $mid);
    if (match_locked($d['matches'][$i])) {
        throw new ApiError('Match is locked');
    }
    return $i;
}

function own_team(array &$d, string $name): int
{
    foreach ($d['teams'] as $t) {
        if (strcasecmp((string) ($t['name'] ?? ''), $name) === 0) {
            return $t['id'];
        }
    }
    $tid = $d['seq']['team'];
    $d['teams'][] = ['id' => $tid, 'name' => $name];
    $d['seq']['team']++;
    return $tid;
}

function pos_of(array $r): int
{
    $v = $r['position'] ?? 0;
    if (is_bool($v)) {
        return 0;
    }
    if (is_int($v)) {
        return $v;
    }
    if (is_float($v)) {
        return (int) $v;
    }
    if (is_string($v)) {
        $t = trim($v);
        if (preg_match('/^[+-]?\d+$/', $t)) {
            return (int) $t;
        }
        return 0;
    }
    return 0;
}

function to_int_lenient($v): ?int
{
    if (is_bool($v)) {
        return null;
    }
    if (is_int($v)) {
        return $v;
    }
    if (is_float($v)) {
        return (int) $v;
    }
    if (is_string($v)) {
        $t = trim($v);
        if (preg_match('/^[+-]?\d+$/', $t)) {
            return (int) $t;
        }
    }
    return null;
}

// ---------- leaderboard ----------

function leaderboard(array $d): array
{
    $ranked = [];
    $matchPts = [];     // match id -> {player id: points}
    $matchPlayers = []; // match id -> [player ids]
    foreach ($d['matches'] as $m) {
        $rows = array_values(array_filter(
            (array) ($m['results'] ?? []),
            static fn ($r) => isset($r['playerId']) && $r['playerId'] !== null
        ));
        $mid = $m['id'];
        if (!$rows) {
            $ranked[$mid] = [];
            $matchPts[$mid] = [];
            $matchPlayers[$mid] = [];
            continue;
        }
        $placed = array_values(array_filter($rows, static fn ($r) => pos_of($r) >= 1));
        usort($placed, static fn ($a, $b) => [pos_of($a), $a['playerId']] <=> [pos_of($b), $b['playerId']]);
        $unplaced = array_values(array_filter($rows, static fn ($r) => pos_of($r) < 1));
        usort($unplaced, static fn ($a, $b) => $a['playerId'] <=> $b['playerId']);
        $out = [];
        $prevPos = null;
        $ptsInMatch = [];
        foreach ($placed as $i => $r) {
            // competition ranking: equal positions share the rank
            $pos = pos_of($r);
            if ($prevPos === null || $pos !== $prevPos) {
                $rank = $i + 1;
                $prevPos = $pos;
            }
            $row = ['playerId' => $r['playerId'], 'position' => $pos, 'rank' => $rank, 'points' => points_for($rank)];
            $out[] = $row;
            $ptsInMatch[$r['playerId']] = $row['points'];
        }
        foreach ($unplaced as $r) {
            $out[] = ['playerId' => $r['playerId'], 'position' => 0, 'rank' => 0, 'points' => 0];
        }
        $ranked[$mid] = $out;
        $matchPts[$mid] = $ptsInMatch;
        $matchPlayers[$mid] = array_map(static fn ($r) => $r['playerId'], $rows);
    }

    $playerTeam = [];
    foreach ($d['players'] as $p) {
        if (($p['teamId'] ?? null) === null) {
            continue;
        }
        $tid = to_int_lenient($p['teamId']);
        if ($tid === null) {
            continue;
        }
        $playerTeam[$p['id']] = $tid;
    }

    $teams = [];
    foreach ($d['teams'] as $t) {
        $memberIds = [];
        foreach ($playerTeam as $pid => $tid) {
            if ($tid === $t['id']) {
                $memberIds[$pid] = $pid;
            }
        }
        $names = [];
        foreach ($d['players'] as $p) {
            if (isset($memberIds[$p['id']])) {
                $names[] = $p['name'];
            }
        }
        // per match, only the team's best-placed member counts
        $contribs = [];
        foreach ($matchPlayers as $mid => $pids) {
            $inMatch = array_intersect(array_keys($memberIds), $pids);
            if ($inMatch) {
                $best = 0;
                foreach ($inMatch as $pid) {
                    $best = max($best, $matchPts[$mid][$pid] ?? 0);
                }
                $contribs[] = $best;
            }
        }
        // only the team's 7 best matches count
        rsort($contribs);
        $teams[] = [
            'id' => $t['id'],
            'name' => $t['name'],
            'points' => array_sum(array_slice($contribs, 0, 7)),
            'players' => $names,
        ];
    }
    usort($teams, static function ($a, $b) {
        $c = $b['points'] <=> $a['points'];
        if ($c !== 0) {
            return $c;
        }
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    // per-player totals: only the player's 7 best matches count
    $teamName = [];
    foreach ($d['teams'] as $t) {
        $teamName[$t['id']] = $t['name'];
    }
    $playerContribs = []; // pid -> [points per match]
    foreach ($matchPts as $pts) {
        foreach ($pts as $pid => $points) {
            $playerContribs[$pid][] = $points;
        }
    }
    $playerMatches = [];
    foreach ($matchPlayers as $pids) {
        foreach ($pids as $pid) {
            $playerMatches[$pid] = ($playerMatches[$pid] ?? 0) + 1;
        }
    }
    $playerBoard = [];
    foreach ($d['players'] as $p) {
        $contribs = $playerContribs[$p['id']] ?? [];
        rsort($contribs);
        $playerBoard[] = [
            'id' => $p['id'],
            'name' => $p['name'],
            'team' => ($teamName[$p['teamId'] ?? ''] ?? null),
            'matches' => $playerMatches[$p['id']] ?? 0,
            'points' => array_sum(array_slice($contribs, 0, 7)),
        ];
    }
    usort($playerBoard, static function ($a, $b) {
        $c = $b['points'] <=> $a['points'];
        if ($c !== 0) {
            return $c;
        }
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return ['teams' => $teams, 'playerBoard' => $playerBoard, 'ranked' => $ranked];
}

// ---------- actions ----------

function act_state(array &$d, array $in): array
{
    $lb = leaderboard($d);
    $matches = [];
    foreach ($d['matches'] as $m) {
        $m['locked'] = match_locked($m);
        $matches[] = $m;
    }
    return [
        'teams' => $d['teams'],
        'players' => $d['players'],
        'maps' => $d['maps'],
        'matches' => $matches,
        'board' => $lb['teams'],
        'playerBoard' => $lb['playerBoard'],
        'ranked' => $lb['ranked'],
    ];
}

function act_team_add(array &$d, array $in): array
{
    $name = in_str($in, 'name', 40);
    if ($name === null) {
        throw new ApiError('Team name required');
    }
    foreach ($d['teams'] as $t) {
        if (strcasecmp((string) $t['name'], $name) === 0) {
            throw new ApiError('Team already exists');
        }
    }
    $d['teams'][] = ['id' => $d['seq']['team'], 'name' => $name];
    $d['seq']['team']++;
    save($d);
    return [];
}

function act_team_rename(array &$d, array $in): array
{
    $tid = in_int($in, 'id');
    $name = in_str($in, 'name', 40);
    if ($tid === null || $name === null) {
        throw new ApiError('Bad input');
    }
    foreach ($d['teams'] as $i => $t) {
        if ($t['id'] === $tid) {
            $d['teams'][$i]['name'] = $name;
        }
    }
    save($d);
    return [];
}

function act_team_delete(array &$d, array $in): array
{
    $tid = in_int($in, 'id');
    if ($tid === null) {
        throw new ApiError('Bad input');
    }
    $d['teams'] = array_values(array_filter($d['teams'], static fn ($t) => $t['id'] !== $tid));
    foreach ($d['players'] as $i => $p) {
        if (($p['teamId'] ?? null) === $tid) {
            $d['players'][$i]['teamId'] = own_team($d, (string) $p['name']);
        }
    }
    save($d);
    return [];
}

function act_player_add(array &$d, array $in): array
{
    $name = in_str($in, 'name', 40);
    if ($name === null) {
        throw new ApiError('Player name required');
    }
    $teamId = in_int($in, 'teamId');
    $teamIds = array_map(static fn ($t) => $t['id'], $d['teams']);
    if ($teamId === null || !in_array($teamId, $teamIds, true)) {
        $teamId = own_team($d, $name);
    }
    $d['players'][] = [
        'id' => $d['seq']['player'],
        'name' => $name,
        'teamId' => $teamId,
        'upgrades' => upgrades_in($in),
    ];
    $d['seq']['player']++;
    save($d);
    return [];
}

function act_player_update(array &$d, array $in): array
{
    $pid = in_int($in, 'id');
    if ($pid === null) {
        throw new ApiError('Bad input');
    }
    $found = false;
    $teamIds = array_map(static fn ($t) => $t['id'], $d['teams']);
    foreach ($d['players'] as $i => $p) {
        if ($p['id'] !== $pid) {
            continue;
        }
        $found = true;
        if (array_key_exists('name', $in) && ($in['name'] ?? null) !== null) {
            $n = in_str($in, 'name', 40);
            if ($n === null) {
                throw new ApiError('Bad name');
            }
            $d['players'][$i]['name'] = $n;
        }
        if (array_key_exists('teamId', $in)) {
            $tid = in_int($in, 'teamId');
            if ($tid === null || !in_array($tid, $teamIds, true)) {
                $d['players'][$i]['teamId'] = own_team($d, (string) $d['players'][$i]['name']);
            } else {
                $d['players'][$i]['teamId'] = $tid;
            }
        }
        if (array_key_exists('upgrades', $in) && ($in['upgrades'] ?? null) !== null) {
            $d['players'][$i]['upgrades'] = upgrades_in($in);
        }
    }
    if (!$found) {
        throw new ApiError('Player not found');
    }
    save($d);
    return [];
}

function act_player_delete(array &$d, array $in): array
{
    $pid = in_int($in, 'id');
    if ($pid === null) {
        throw new ApiError('Bad input');
    }
    $d['players'] = array_values(array_filter($d['players'], static fn ($p) => $p['id'] !== $pid));
    foreach ($d['matches'] as $mi => $m) {
        $d['matches'][$mi]['results'] = array_values(array_filter(
            (array) ($m['results'] ?? []),
            static fn ($r) => ($r['playerId'] ?? null) !== $pid
        ));
    }
    save($d);
    return [];
}

function act_map_add(array &$d, array $in): array
{
    $name = in_str($in, 'name', 40);
    if ($name === null) {
        throw new ApiError('Map name required');
    }
    foreach ($d['maps'] as $m) {
        if (strcasecmp((string) $m, $name) === 0) {
            throw new ApiError('Map already exists');
        }
    }
    $d['maps'][] = $name;
    save($d);
    return [];
}

function act_map_delete(array &$d, array $in): array
{
    $name = in_str($in, 'name');
    if ($name === null) {
        throw new ApiError('Bad input');
    }
    $d['maps'] = array_values(array_filter($d['maps'], static fn ($m) => $m !== $name));
    save($d);
    return [];
}

function act_match_add(array &$d, array $in): array
{
    $m = in_str($in, 'map', 40);
    $dt = in_str($in, 'date', 10);
    if ($m === null) {
        throw new ApiError('Map required');
    }
    if ($dt === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
        throw new ApiError('Date required (YYYY-MM-DD)');
    }
    if (!in_array($m, $d['maps'], true)) {
        $d['maps'][] = $m;
    }
    $d['matches'][] = [
        'id' => $d['seq']['match'],
        'map' => $m,
        'date' => $dt,
        'results' => array_map(static fn ($p) => ['playerId' => $p['id'], 'position' => 0], $d['players']),
    ];
    $d['seq']['match']++;
    save($d);
    return [];
}

function act_match_update(array &$d, array $in): array
{
    $mid = in_int($in, 'id');
    if ($mid === null) {
        throw new ApiError('Bad input');
    }
    $i = require_unlocked($d, $mid);
    if (array_key_exists('map', $in) && ($in['map'] ?? null) !== null) {
        $mname = in_str($in, 'map', 40);
        if ($mname !== null) {
            $d['matches'][$i]['map'] = $mname;
            if (!in_array($mname, $d['maps'], true)) {
                $d['maps'][] = $mname;
            }
        }
    }
    if (array_key_exists('date', $in) && ($in['date'] ?? null) !== null) {
        $dt = in_str($in, 'date', 10);
        if ($dt !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
            $d['matches'][$i]['date'] = $dt;
        }
    }
    save($d);
    return [];
}

function act_match_delete(array &$d, array $in): array
{
    $mid = in_int($in, 'id');
    if ($mid === null) {
        throw new ApiError('Bad input');
    }
    $i = find_match($d, $mid);
    if (match_locked($d['matches'][$i])) {
        throw new ApiError('Match is locked');
    }
    $d['matches'] = array_values(array_filter($d['matches'], static fn ($m) => $m['id'] !== $mid));
    save($d);
    return [];
}

function act_match_add_player(array &$d, array $in): array
{
    $mid = in_int($in, 'matchId');
    $pid = in_int($in, 'playerId');
    if ($mid === null || $pid === null) {
        throw new ApiError('Bad input');
    }
    $playerIds = array_map(static fn ($p) => $p['id'], $d['players']);
    if (!in_array($pid, $playerIds, true)) {
        throw new ApiError('Player not found');
    }
    $i = require_unlocked($d, $mid);
    $results = (array) $d['matches'][$i]['results'];
    $resultPids = array_map(static fn ($r) => $r['playerId'], $results);
    if (!in_array($pid, $resultPids, true)) {
        $pos = in_int($in, 'position', 0);
        $pos = max(0, $pos === null ? 0 : $pos);
        if ($pos >= 1 && any_at_position($results, $pos)) {
            throw new ApiError('Position already taken');
        }
        $results[] = ['playerId' => $pid, 'position' => $pos];
        $d['matches'][$i]['results'] = $results;
        save($d);
    }
    return [];
}

function any_at_position(array $results, int $pos): bool
{
    foreach ($results as $r) {
        if (pos_of($r) === $pos) {
            return true;
        }
    }
    return false;
}

function act_match_set_position(array &$d, array $in): array
{
    $mid = in_int($in, 'matchId');
    $pid = in_int($in, 'playerId');
    $pos = in_int($in, 'position');
    if ($mid === null || $pid === null || $pos === null || $pos < 0) {
        throw new ApiError('Bad input');
    }
    $i = require_unlocked($d, $mid);
    $results = (array) $d['matches'][$i]['results'];
    if ($pos >= 1) {
        foreach ($results as $r) {
            if (($r['playerId'] ?? null) !== $pid && pos_of($r) === $pos) {
                throw new ApiError('Position already taken');
            }
        }
    }
    foreach ($results as $ri => $r) {
        if (($r['playerId'] ?? null) === $pid) {
            $results[$ri]['position'] = $pos;
        }
    }
    $d['matches'][$i]['results'] = $results;
    save($d);
    return [];
}

function act_match_remove_player(array &$d, array $in): array
{
    $mid = in_int($in, 'matchId');
    $pid = in_int($in, 'playerId');
    if ($mid === null || $pid === null) {
        throw new ApiError('Bad input');
    }
    $i = require_unlocked($d, $mid);
    $d['matches'][$i]['results'] = array_values(array_filter(
        (array) $d['matches'][$i]['results'],
        static fn ($r) => ($r['playerId'] ?? null) !== $pid
    ));
    save($d);
    return [];
}

function act_match_unlock(array &$d, array $in): array
{
    $mid = in_int($in, 'id');
    if ($mid === null) {
        throw new ApiError('Bad input');
    }
    $i = find_match($d, $mid);
    $d['matches'][$i]['unlocked'] = true;
    unset($d['matches'][$i]['locked']);
    save($d);
    return [];
}

function act_match_lock(array &$d, array $in): array
{
    $mid = in_int($in, 'id');
    if ($mid === null) {
        throw new ApiError('Bad input');
    }
    $i = find_match($d, $mid);
    $d['matches'][$i]['locked'] = true;
    unset($d['matches'][$i]['unlocked']);
    save($d);
    return [];
}

function act_wipe(array &$d, array $in): array
{
    save(defaults());
    return [];
}

/* ROLES-FRAMEWORK:START - disabled. To enable, delete this line and the matching :END line.
// ---------- roles / privileges ----------
// Two roles:
//   modify    - full access (all actions)
//   view-only - read only (action "state")
//
// To plug into a bigger app:
//   1. Replace the body of current_role() with your own auth (session, SSO,
//      cookie, ...) and return the role of the current user. Never trust
//      client input in production - the ?role= / X-Role handling below is a
//      test stub only.
//   2. Also delete the :START/:END lines around the gate in the dispatch
//      section below.
// current_role() returns null when no role is assigned -> open access
// (current behavior). When a role IS assigned it is matched strictly:
// only modify / view-only are allowed, anything else is denied.

const ROLE_MODIFY = 'modify';
const ROLE_VIEW_ONLY = 'view-only';

function current_role(): ?string
{
    // TEST STUB ONLY - replace in the bigger app.
    $r = strtolower((string) ($_GET['role'] ?? $_SERVER['HTTP_X_ROLE'] ?? ''));
    return $r === '' ? null : $r;
}

function role_can(?string $role, string $action): bool
{
    if ($role === null) {
        return true; // no role assigned -> open access
    }
    if ($role === ROLE_MODIFY) {
        return true; // full access
    }
    if ($role === ROLE_VIEW_ONLY) {
        return $action === 'state';
    }
    return false; // unknown role -> deny
}
ROLES-FRAMEWORK:END - delete this line and the matching :START line to enable. */

// ---------- dispatch ----------

$HANDLERS = [
    'state' => 'act_state',
    'team_add' => 'act_team_add',
    'team_rename' => 'act_team_rename',
    'team_delete' => 'act_team_delete',
    'player_add' => 'act_player_add',
    'player_update' => 'act_player_update',
    'player_delete' => 'act_player_delete',
    'map_add' => 'act_map_add',
    'map_delete' => 'act_map_delete',
    'match_add' => 'act_match_add',
    'match_update' => 'act_match_update',
    'match_delete' => 'act_match_delete',
    'match_add_player' => 'act_match_add_player',
    'match_set_position' => 'act_match_set_position',
    'match_remove_player' => 'act_match_remove_player',
    'match_unlock' => 'act_match_unlock',
    'match_lock' => 'act_match_lock',
    'wipe' => 'act_wipe',
];

function get_input(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            return $j;
        }
    }
    return $_POST;
}

function get_action(array $in): string
{
    $action = $_GET['action'] ?? null;
    if ($action === null) {
        $action = $in['action'] ?? null;
    }
    return $action === null ? '' : (string) $action;
}

function respond(array $payload, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$in = get_input();
$action = get_action($in);
/* ROLES-FRAMEWORK:START - disabled. To enable, delete this line and the matching :END line.
$role = current_role();
if (!role_can($role, $action)) {
    respond(['ok' => false, 'error' => 'Forbidden'], 403);
    return;
}
ROLES-FRAMEWORK:END - delete this line and the matching :START line to enable. */
$d = load();
try {
    $handler = $HANDLERS[$action] ?? null;
    if ($handler === null) {
        throw new ApiError('Unknown action');
    }
    $extra = $handler($d, $in) ?: [];
    respond(['ok' => true] + $extra, 200);
} catch (ApiError $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
}
