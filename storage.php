<?php
declare(strict_types=1);

/**
 * Heat Liga - storage layer (SQLite for now).
 *
 * Contract for the rest of the app:
 *   storage_load()        -> array   the whole dataset (see defaults())
 *   storage_save(array $d)         replace the whole dataset, atomically
 *
 * To use a different database later, replace this file while keeping that
 * contract - nothing else in the app needs to change.
 */

define('DB_FILE', __DIR__ . '/data.sqlite');

function defaults(): array
{
    return [
        'teams' => [],     // {id, name}
        'players' => [],   // {id, name, teamId, upgrades[]}
        'maps' => [],      // [name]
        'matches' => [],   // {id, map, date, results:[{playerId, position}]}
        'seq' => ['team' => 1, 'player' => 1, 'match' => 1],
    ];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 2000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS players (id INTEGER PRIMARY KEY, name TEXT NOT NULL, team_id INTEGER, upgrades TEXT NOT NULL DEFAULT '[]')");
        $pdo->exec('CREATE TABLE IF NOT EXISTS maps (name TEXT PRIMARY KEY)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS matches (id INTEGER PRIMARY KEY, map TEXT NOT NULL, date TEXT NOT NULL, locked INTEGER NOT NULL DEFAULT 0, unlocked INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS match_results (match_id INTEGER NOT NULL, player_id INTEGER NOT NULL, position INTEGER NOT NULL DEFAULT 0, ord INTEGER NOT NULL, PRIMARY KEY (match_id, ord))');
        $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    }
    return $pdo;
}

function storage_load(): array
{
    $d = defaults();
    $pdo = db();
    foreach ($pdo->query('SELECT id, name FROM teams ORDER BY id') as $r) {
        $d['teams'][] = ['id' => (int) $r['id'], 'name' => $r['name']];
    }
    foreach ($pdo->query('SELECT id, name, team_id, upgrades FROM players ORDER BY id') as $r) {
        $up = json_decode((string) $r['upgrades'], true);
        $d['players'][] = [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'teamId' => $r['team_id'] === null ? null : (int) $r['team_id'],
            'upgrades' => is_array($up) ? $up : [],
        ];
    }
    foreach ($pdo->query('SELECT name FROM maps ORDER BY rowid') as $r) {
        $d['maps'][] = $r['name'];
    }
    $stRes = $pdo->prepare('SELECT player_id, position FROM match_results WHERE match_id = ? ORDER BY ord');
    foreach ($pdo->query('SELECT id, map, date, locked, unlocked FROM matches ORDER BY id') as $m) {
        $entry = ['id' => (int) $m['id'], 'map' => $m['map'], 'date' => $m['date'], 'results' => []];
        if ((int) $m['locked'] === 1) {
            $entry['locked'] = true;
        }
        if ((int) $m['unlocked'] === 1) {
            $entry['unlocked'] = true;
        }
        $stRes->execute([(int) $m['id']]);
        foreach ($stRes as $r) {
            $entry['results'][] = ['playerId' => (int) $r['player_id'], 'position' => (int) $r['position']];
        }
        $d['matches'][] = $entry;
    }
    $seq = $pdo->query("SELECT value FROM meta WHERE key = 'seq'")->fetchColumn();
    if ($seq !== false) {
        $s = json_decode((string) $seq, true);
        if (is_array($s)) {
            $d['seq'] = array_merge(defaults()['seq'], $s);
        }
    }
    return $d;
}

function storage_save(array $d): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM match_results');
    $pdo->exec('DELETE FROM matches');
    $pdo->exec('DELETE FROM players');
    $pdo->exec('DELETE FROM teams');
    $pdo->exec('DELETE FROM maps');
    $pdo->exec('DELETE FROM meta');

    $stTeam = $pdo->prepare('INSERT INTO teams (id, name) VALUES (?, ?)');
    foreach ((array) ($d['teams'] ?? []) as $t) {
        $stTeam->execute([(int) $t['id'], (string) $t['name']]);
    }
    $stPlayer = $pdo->prepare('INSERT INTO players (id, name, team_id, upgrades) VALUES (?, ?, ?, ?)');
    foreach ((array) ($d['players'] ?? []) as $p) {
        $tid = $p['teamId'] ?? null;
        $ups = json_encode((array) ($p['upgrades'] ?? []));
        $stPlayer->execute([(int) $p['id'], (string) $p['name'], $tid === null ? null : (int) $tid, $ups]);
    }
    $stMap = $pdo->prepare('INSERT INTO maps (name) VALUES (?)');
    foreach ((array) ($d['maps'] ?? []) as $m) {
        $stMap->execute([(string) $m]);
    }
    $stMatch = $pdo->prepare('INSERT INTO matches (id, map, date, locked, unlocked) VALUES (?, ?, ?, ?, ?)');
    $stRes = $pdo->prepare('INSERT INTO match_results (match_id, player_id, position, ord) VALUES (?, ?, ?, ?)');
    foreach ((array) ($d['matches'] ?? []) as $m) {
        $stMatch->execute([
            (int) $m['id'],
            (string) $m['map'],
            (string) $m['date'],
            !empty($m['locked']) ? 1 : 0,
            !empty($m['unlocked']) ? 1 : 0,
        ]);
        $ord = 0;
        foreach ((array) ($m['results'] ?? []) as $r) {
            $stRes->execute([(int) $m['id'], (int) $r['playerId'], (int) $r['position'], $ord]);
            $ord++;
        }
    }
    $stMeta = $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?)');
    $stMeta->execute(['seq', (string) json_encode((array) ($d['seq'] ?? defaults()['seq']))]);

    $pdo->commit();
}
