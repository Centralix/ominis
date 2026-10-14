<?php
declare(strict_types=1);

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function redirect(string $url): never { header('Location: ' . $url, true, 303); exit; }
function flash(string $message, string $kind = 'info'): void { $_SESSION['flash'] = [$message, $kind]; }
function post_string(string $key): string { return is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : ''; }

/** Strict, human-readable TXT parser. Invalid questions are never silently published. */
function parse_item(string $file): array {
    $text = file_get_contents($file);
    if ($text === false || strlen($text) > 2000000) throw new RuntimeException('Fichier illisible ou trop volumineux.');
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $lines = preg_split('/\R/u', $text);
    $item = ['id' => '', 'title' => '', 'college' => '', 'questions' => []]; $q = null;
    foreach ($lines as $n => $raw) {
        $line = trim($raw);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (preg_match('/^ITEM\s+(\d+)\s*\|\s*(.+)$/u', $line, $m)) { $item['id'] = $m[1]; $item['title'] = trim($m[2]); continue; }
        if ($q === null && preg_match('/^COLLEGE\s*:\s*(.+)$/u', $line, $m)) { $item['college'] = trim($m[1]); continue; }
        if ($line === '---') {
            if ($q !== null) $item['questions'][] = $q;
            $q = ['choices' => [], 'why' => []]; continue;
        }
        if ($q === null || !str_contains($line, ':')) throw new RuntimeException('Syntaxe invalide, ligne ' . ($n + 1));
        [$key, $value] = array_map('trim', explode(':', $line, 2));
        $map = ['ID'=>'id','TYPE'=>'type','RANG'=>'rank','FORMAT'=>'format','THEME'=>'theme','SOURCE'=>'source','CONSIGNE'=>'prompt','JUSTIFICATION'=>'explanation'];
        if (isset($map[$key])) $q[$map[$key]] = $value;
        elseif (preg_match('/^[A-E]$/', $key)) {
            $parts = explode('|', $value, 2);
            if (count($parts) !== 2 || !in_array(trim($parts[0]), ['V', 'F'], true)) throw new RuntimeException('Réponse V/F manquante, ligne ' . ($n + 1));
            $q['choices'][$key] = ['correct' => trim($parts[0]) === 'V', 'text' => trim($parts[1])];
        } elseif (preg_match('/^EXPLICATION_([A-E])$/', $key, $m)) $q['why'][$m[1]] = $value;
        else throw new RuntimeException('Champ inconnu, ligne ' . ($n + 1));
    }
    if ($q !== null) $item['questions'][] = $q;
    if (!$item['id'] || !$item['title'] || !$item['college'] || !$item['questions']) throw new RuntimeException('Entête ITEM, champ COLLEGE ou questions manquants.');
    $seen = [];
    foreach ($item['questions'] as &$question) {
        foreach (['id','type','rank','format','theme','source','prompt','explanation'] as $field)
            if (empty($question[$field])) throw new RuntimeException('Champ obligatoire manquant : ' . $field);
        if (!preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $question['id']) || isset($seen[$question['id']])) throw new RuntimeException('ID invalide ou dupliqué.');
        $seen[$question['id']] = true;
        if (!in_array($question['type'], ['QCM','QRU'], true) || !in_array($question['rank'], ['A','B'], true) || !in_array($question['format'], ['CAS','CONNAISSANCE'], true)) throw new RuntimeException('Type, format ou rang invalide.');
        if (array_keys($question['choices']) !== ['A','B','C','D','E'] || array_keys($question['why']) !== ['A','B','C','D','E']) throw new RuntimeException('Cinq propositions et cinq explications A à E sont obligatoires.');
        $truths = count(array_filter($question['choices'], fn($c) => $c['correct']));
        if ($truths < 1 || ($question['type'] === 'QRU' && $truths !== 1)) throw new RuntimeException('Nombre de bonnes réponses invalide.');
        foreach ($question['choices'] as $key => $choice) if (!$choice['text'] || !$question['why'][$key]) throw new RuntimeException('Proposition ou justification vide.');
        $question['item'] = $item['id']; $question['item_title'] = $item['title'];
        $question['hash'] = hash('sha256', json_encode($question, JSON_UNESCAPED_UNICODE));
    }
    unset($question); return $item;
}

function bank(): array {
    $items = []; $questions = []; $errors = [];
    foreach (glob(__DIR__ . '/../qcm/*.txt') ?: [] as $file) {
        try {
            $item = parse_item($file);
            if (isset($items[$item['id']])) throw new RuntimeException('Numéro d’item déjà présent.');
            foreach ($item['questions'] as $q) if (isset($questions[$q['id']])) throw new RuntimeException('Identifiant de question déjà présent.');
            $items[$item['id']] = $item;
            foreach ($item['questions'] as $q) $questions[$q['id']] = $q;
        } catch (Throwable $ex) { $errors[] = basename($file) . ' : ' . $ex->getMessage(); }
    }
    uasort($items, fn($a,$b)=>strnatcasecmp($a['college'],$b['college']) ?: strnatcasecmp($a['title'],$b['title']));
    return [$items, $questions, $errors];
}

function grade(array $q, array $answers): array {
    $wrong = []; $falsePositive = []; $falseNegative = [];
    foreach ($q['choices'] as $letter => $choice) {
        if (!array_key_exists($letter, $answers) || !is_bool($answers[$letter])) throw new InvalidArgumentException('Réponse incomplète.');
        if ($answers[$letter] !== $choice['correct']) {
            $wrong[] = $letter;
            if ($answers[$letter]) $falsePositive[] = $letter; else $falseNegative[] = $letter;
        }
    }
    if ($q['type'] === 'QRU' && count(array_filter($answers)) !== 1) throw new InvalidArgumentException('Une seule réponse attendue.');
    $count = count($wrong);
    $score = $q['type'] === 'QRU' ? ($count === 0 ? 1.0 : 0.0) : ([1.0, 0.7, 0.2][$count] ?? 0.0);
    return compact('score', 'wrong', 'falsePositive', 'falseNegative');
}

/** PHP envelope prevents disclosure even if a storage URL is accidentally reachable. */
function storage_directory(): string {
    $dir = getenv('OMINIS_DATA_DIR') ?: __DIR__ . '/../storage';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Stockage indisponible.');
    return $dir;
}
function profile_path(string $id): string {
    if (!preg_match('/^[a-f0-9]{64}$/D', $id)) throw new RuntimeException('Profil invalide.');
    return storage_directory() . '/' . $id . '.php';
}
function storage_lock(int $mode) {
    $lock = fopen(storage_directory() . '/.storage.lock', 'c+b');
    if (!$lock) throw new RuntimeException('Verrouillage impossible.');
    if (!flock($lock, $mode)) { fclose($lock); throw new RuntimeException('Verrouillage impossible.'); }
    return $lock;
}
function storage_cutoff(?int $now = null): int {
    $date = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new DateTimeZone('Europe/Paris'));
    $cutoff = $date->setTime(3, 0);
    if ($date < $cutoff) $cutoff = $cutoff->modify('-1 day');
    return $cutoff->getTimestamp();
}
/** Remove only profile data older than the latest 03:00 Paris boundary. */
function cleanup_storage(?int $now = null): int {
    $cutoff = storage_cutoff($now); $removed = 0;
    $lock = storage_lock(LOCK_EX);
    try {
        $dir = storage_directory();
        foreach (glob($dir . '/*.php') ?: [] as $path) {
            if (!preg_match('/^[a-f0-9]{64}\.php$/D', basename($path)) || is_link($path)) continue;
            clearstatcache(true, $path);
            $modified = filemtime($path);
            if ($modified === false) throw new RuntimeException('Date de stockage indisponible.');
            if ($modified < $cutoff) {
                if (!unlink($path)) throw new RuntimeException('Nettoyage du stockage impossible.');
                $removed++;
            }
        }
        return $removed;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function read_profile(string $id): array {
    $lock = storage_lock(LOCK_SH);
    try {
    $path = profile_path($id);
    if (!is_file($path)) return ['history'=>[], 'latest'=>[], 'reports'=>[]];
    $f = fopen($path, 'rb'); if (!$f) throw new RuntimeException('Stockage indisponible.');
    flock($f, LOCK_SH); $raw = stream_get_contents($f); flock($f, LOCK_UN); fclose($f);
    $data = json_decode(substr($raw, strlen("<?php exit; ?>\n")), true, 512, JSON_THROW_ON_ERROR);
    return $data;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function update_profile(string $id, callable $update, bool $removeAfter = false): array {
    $lock = storage_lock(LOCK_EX);
    try {
    $path = profile_path($id);
    $f = fopen($path, 'c+b'); if (!$f) throw new RuntimeException('Stockage indisponible.');
    if (!flock($f, LOCK_EX)) { fclose($f); throw new RuntimeException('Verrouillage impossible.'); }
    try {
        $raw = stream_get_contents($f);
        $data = $raw ? json_decode(substr($raw, strlen("<?php exit; ?>\n")), true, 512, JSON_THROW_ON_ERROR) : ['history'=>[], 'latest'=>[], 'reports'=>[]];
        $data = $update($data);
        if ($removeAfter) {
            flock($f, LOCK_UN); fclose($f); $f = null;
            if (!unlink($path)) throw new RuntimeException('Suppression du stockage impossible.');
            return $data;
        }
        $encoded = "<?php exit; ?>\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        rewind($f); if (fwrite($f, $encoded) !== strlen($encoded)) throw new RuntimeException('Écriture incomplète.');
        ftruncate($f, strlen($encoded)); fflush($f); return $data;
    } finally { if (is_resource($f)) { flock($f, LOCK_UN); fclose($f); } }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function mistakes(array $profile, array $questions): array {
    return array_filter($questions, fn($q) => isset($profile['latest'][$q['id']]) && $profile['latest'][$q['id']]['hash'] === $q['hash'] && $profile['latest'][$q['id']]['score'] < 1);
}
function build_series(array $pool, int $count, bool $retry = false): array {
    $pool = array_values($pool); $count = min($count, count($pool));
    if ($count < 1) throw new RuntimeException('Aucune question ne correspond à cette sélection.');
    if ($retry) { shuffle($pool); return array_slice($pool, 0, $count); }
    $a = array_values(array_filter($pool, fn($q)=>$q['rank']==='A'));
    $b = array_values(array_filter($pool, fn($q)=>$q['rank']==='B'));
    $needA = (int)round($count * 0.7); $needB = $count - $needA;
    if (count($a) < $needA || count($b) < $needB) throw new RuntimeException('La sélection ne permet pas une série à 70 % A / 30 % B. Ajoutez des questions ou choisissez moins de questions.');
    shuffle($a); shuffle($b); $series = array_merge(array_slice($a, 0, $needA), array_slice($b, 0, $needB)); shuffle($series); return $series;
}
function complete_run(string $id, array $run, bool $checkActive = false): string {
    $attemptId = $run['id'];
    $completed = update_profile($id, function($profile) use ($run, $attemptId, $checkActive) {
        foreach ($profile['history'] as $old) if ($old['id'] === $attemptId) return $profile;
        if ($checkActive && (($profile['active']['id'] ?? '') !== $attemptId || ($profile['active']['revision'] ?? null) !== $run['revision'])) throw new RuntimeException('La série a changé. Rechargez la page pour reprendre.');
        $results = []; $total = 0;
        foreach ($run['questions'] as $q) {
            $answers = $run['answers'][$q['id']] ?? null;
            if ($answers === null) throw new RuntimeException('Répondez à toutes les questions avant de terminer.');
            $result = grade($q, $answers); $total += $result['score'];
            $results[] = ['question'=>$q, 'answers'=>$answers, 'note'=>$run['notes'][$q['id']] ?? ''] + $result;
            $profile['latest'][$q['id']] = ['score'=>$result['score'], 'hash'=>$q['hash'], 'at'=>time()];
        }
        array_unshift($profile['history'], ['id'=>$attemptId,'at'=>time(),'mode'=>$run['mode'],'retry'=>$run['retry'],'duration'=>time()-$run['started'],'score'=>$total,'results'=>$results]);
        $profile['history'] = array_slice($profile['history'], 0, 100);
        if (($profile['active']['id'] ?? '') === $attemptId) unset($profile['active']);
        return $profile;
    }, true);
    // Keep only the just-finished result in the PHP session, never in storage.
    $_SESSION['completed_attempt'] = $completed['history'][0];
    return $attemptId;
}
function fmt(float|int $v, int $decimals = 1): string { return number_format($v, $decimals, ',', ' '); }
