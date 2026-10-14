<?php
declare(strict_types=1);
require __DIR__ . '/app/core.php';
require __DIR__ . '/app/smtp.php';

date_default_timezone_set('Europe/Paris');
$secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
session_name('ominis_session');
session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Lax','path'=>'/']);
session_start();
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
$cookie = $_COOKIE['ominis_profile'] ?? '';
$profileId = is_string($cookie) && preg_match('/^[a-f0-9]{64}$/D', $cookie) ? $cookie : bin2hex(random_bytes(32));
setcookie('ominis_profile', $profileId, ['expires'=>time()+365*86400,'httponly'=>true,'secure'=>$secure,'samesite'=>'Lax','path'=>'/']);
[$items, $questions, $bankErrors] = bank();
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'home';
if (!in_array($page, ['home','quiz','results','errors','analysis','guide','sheets'], true)) { http_response_code(404); $page = 'home'; }
$fatal = null;
try { cleanup_storage(); $profile = read_profile($profileId); }
catch (Throwable $ex) { error_log('Ominis storage read failed'); $fatal = 'Votre progression est momentanément indisponible. Vérifiez les droits d’écriture du dossier storage.'; $profile = ['history'=>[],'latest'=>[],'reports'=>[]]; }
$errorBank = mistakes($profile, $questions);
$run = $profile['active'] ?? null;
$attempt = null;
$attemptId = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
if (isset($_SESSION['completed_attempt']) && ($_SESSION['completed_attempt']['at'] ?? 0) < storage_cutoff()) unset($_SESSION['completed_attempt']);
if (($_SESSION['completed_attempt']['id'] ?? null) === $attemptId) $attempt = $_SESSION['completed_attempt'];
foreach ($profile['history'] as $candidate) if ($candidate['id'] === $attemptId) $attempt = $candidate;

function action_run(array $profile): array {
    $active = $profile['active'] ?? null;
    if (!$active || $active['id'] !== post_string('run') || (string)$active['revision'] !== post_string('revision')) throw new RuntimeException('Cette série a changé dans un autre onglet. Rechargez la page pour reprendre.');
    return $active;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'index.php?page=' . $page;
    if ($page === 'quiz' && $run) $back .= '&i=' . max(0,min(count($run['questions']),(int)($_GET['i'] ?? $run['position'])));
    if ($page === 'results' && $attempt) $back .= '&id=' . urlencode($attempt['id']);
    try {
        if (!hash_equals(csrf(), post_string('csrf'))) throw new RuntimeException('La session a expiré. Rechargez la page et réessayez.');
        if ($fatal) throw new RuntimeException($fatal);
        switch (post_string('action')) {
            case 'start':
                $retry = post_string('retry') === '1';
                $mode = post_string('mode');
                if (!in_array($mode, ['practice','exam'], true)) throw new RuntimeException('Choisissez un mode de correction.');
                $selected = $_POST['items'] ?? [];
                if (!is_array($selected)) throw new RuntimeException('Sélection d’items invalide.');
                $selected = array_filter($selected, fn($v)=>is_string($v) || is_int($v));
                $pool = $retry ? $errorBank : array_filter($questions, fn($q)=>in_array($q['item'], array_map('strval', $selected), true));
                $amount = post_string('count');
                if ($amount !== 'all' && (!preg_match('/^[1-9][0-9]*$/D', $amount) || (float)$amount > count($questions))) throw new RuntimeException('Nombre de questions invalide.');
                $count = $amount === 'all' ? count($pool) : (int)$amount;
                if (!$retry && $count > count($pool)) throw new RuntimeException('La sélection contient moins de questions que demandé.');
                $series = build_series($pool, $count, $retry);
                $newRun = ['id'=>bin2hex(random_bytes(12)),'revision'=>1,'questions'=>$series,'answers'=>[],'mode'=>$mode,'retry'=>$retry,'started'=>time(),'position'=>0];
                update_profile($profileId, function($p) use ($newRun) { if (!empty($p['active'])) throw new RuntimeException('Une série est déjà en cours. Reprenez-la ou abandonnez-la avant d’en démarrer une autre.'); $p['active']=$newRun; return $p; });
                unset($_SESSION['completed_attempt'], $_SESSION['report_draft']);
                redirect('index.php?page=quiz');
            case 'answer':
                $profile = update_profile($profileId, function($p) {
                    $active = action_run($p);
                    $qid = post_string('question'); $q = null; $position = null;
                    foreach ($active['questions'] as $i=>$candidate) if ($candidate['id']===$qid) { $q=$candidate; $position=$i; }
                    if (!$q || ($active['mode']==='practice' && isset($active['answers'][$qid]))) throw new RuntimeException('Cette question a déjà été corrigée ou n’est plus la question courante.');
                    $answers = [];
                    if ($q['type'] === 'QRU') {
                        $pick = post_string('pick'); if (!in_array($pick, ['A','B','C','D','E'], true)) throw new RuntimeException('Choisissez une réponse.');
                        foreach ($q['choices'] as $letter=>$choice) $answers[$letter] = $letter===$pick;
                    } else {
                        $input = $_POST['answers'] ?? [];
                        if (!is_array($input)) throw new RuntimeException('Réponses invalides.');
                        foreach ($q['choices'] as $letter=>$choice) {
                            if (!isset($input[$letter]) || !in_array($input[$letter], ['V','F'], true)) throw new RuntimeException('Indiquez vrai ou faux pour chacune des cinq propositions.');
                            $answers[$letter]=$input[$letter]==='V';
                        }
                    }
                    grade($q, $answers);
                    $note=post_string('reasoning');
                    if (strlen($note)>16000 || preg_match_all('/./us',$note)>4000) throw new RuntimeException('Le raisonnement est limité à 4 000 caractères.');
                    $active['notes'][$qid]=$note;
                    $active['answers'][$qid]=$answers; $active['revision']++;
                    $active['position']=$active['mode']==='exam' ? min($position+1,count($active['questions'])) : $position;
                    $p['active']=$active; return $p;
                });
                $active=$profile['active'];
                redirect('index.php?page=quiz&i=' . $active['position']);
            case 'next':
                $profile=update_profile($profileId, function($p) {
                    $active=action_run($p); $q=$active['questions'][$active['position']] ?? null;
                    if ($active['mode']!=='practice' || !$q || !isset($active['answers'][$q['id']])) throw new RuntimeException('Validez d’abord la réponse à la question.');
                    if ($active['position'] < count($active['questions'])-1) $active['position']++;
                    $active['revision']++; $p['active']=$active; return $p;
                });
                redirect('index.php?page=quiz');
            case 'finish':
                $finished=action_run($profile);
                if (count($finished['answers'])!==count($finished['questions'])) throw new RuntimeException('Répondez aux questions restantes avant de terminer.');
                $id=complete_run($profileId,$finished,true);
                unset($_SESSION['report_draft']);
                redirect('index.php?page=results&id=' . $id);
            case 'abandon':
                update_profile($profileId, function($p) { action_run($p); return $p; }, true);
                unset($_SESSION['completed_attempt'], $_SESSION['report_draft']);
                flash('La série a été abandonnée. Vos bilans précédents sont conservés.'); redirect('index.php');
            case 'report':
                if (post_string('website') !== '') throw new RuntimeException('Signalement invalide.');
                if ($page!=='quiz') throw new RuntimeException('Le signalement est disponible pendant une question.');
                $reportRun=action_run($profile);
                $reportPosition=max(0,min(count($reportRun['questions'])-1,(int)($_GET['i'] ?? $reportRun['position'])));
                $reportQ=$reportRun['questions'][$reportPosition] ?? null;
                $qid=post_string('report_question');
                if (!$reportQ || $reportQ['id']!==$qid) throw new RuntimeException('Signalez uniquement la question en cours.');
                $message=post_string('message');
                if (!$reportQ) throw new RuntimeException('Choisissez une question à signaler.');
                $length=preg_match_all('/./us',$message);
                if ($length < 15 || $length > 4000) throw new RuntimeException('Votre explication doit contenir entre 15 et 4 000 caractères.');
                // Reserve a rate-limit slot while holding a file lock, including across browser sessions.
                $reservation=bin2hex(random_bytes(8));
                update_profile($profileId,function($p) use ($reservation) {
                    $p['reports']=array_filter($p['reports'] ?? [],fn($t)=>$t>time()-86400);
                    if (count($p['reports'])>=5 || ($p['reports'] && max($p['reports'])>time()-60)) throw new RuntimeException('Merci de patienter une minute entre deux signalements (cinq par jour maximum).');
                    $p['reports'][$reservation]=time(); return $p;
                });
                try {
                    $body="Signalement Ominis\nQuestion : " . $reportQ['id'] . "\nItem : " . $reportQ['item'] . "\nRang : " . $reportQ['rank'] . "\nVersion : " . $reportQ['hash'] . "\nConsigne : " . $reportQ['prompt'] . "\n\n";
                    foreach ($reportQ['choices'] as $letter=>$choice) $body.=$letter . '. ' . $choice['text'] . ' [' . ($choice['correct'] ? 'Vrai':'Faux') . "]\n";
                    $body.="\nJustification : " . $reportQ['explanation'] . "\nSource : " . $reportQ['source'] . "\n\nArgument de l’étudiant :\n" . $message;
                    smtp_send(require __DIR__.'/config/mail.php', 'Ominis · Signalement ' . $reportQ['id'], $body);
                } catch (Throwable $ex) {
                    update_profile($profileId, function($p) use ($reservation) { unset($p['reports'][$reservation]); return $p; });
                    error_log('Ominis SMTP: delivery failed (code ' . (int)$ex->getCode() . ')');
                    $_SESSION['report_draft']=['question'=>$qid,'message'=>$message];
                    if ($ex->getCode() === 535) throw new RuntimeException('Le service de signalement est momentanément indisponible : la configuration de messagerie doit être corrigée. Votre texte est conservé.');
                    throw new RuntimeException('Le serveur de messagerie n’a pas accepté le signalement. Votre texte est conservé ; vous pouvez réessayer.');
                }
                unset($_SESSION['report_draft']); flash('Votre signalement a été transmis. Merci pour votre contribution.', 'success'); redirect($back);
            default: throw new RuntimeException('Action inconnue.');
        }
    } catch (Throwable $ex) {
        // Never expose stack traces, addresses or SMTP credentials in responses.
        $message=$ex instanceof RuntimeException || $ex instanceof InvalidArgumentException ? $ex->getMessage() : 'Une erreur technique est survenue. Réessayez dans un instant.';
        flash($message,'error'); redirect($back);
    }
}
if ($page==='quiz' && !$run) { flash('Choisissez une série pour commencer.'); redirect('index.php'); }
if ($page==='results' && !$attempt) { flash('Ce bilan est introuvable pour ce navigateur.','error'); redirect('index.php?page=analysis'); }
$position = $run ? max(0,min(count($run['questions'])-($run['mode']==='practice'?1:0),(int)($_GET['i'] ?? $run['position']))) : 0;
$currentQ = $page==='quiz' ? ($run['questions'][$position] ?? null) : null;
$notice=$_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$draft=$_SESSION['report_draft'] ?? null;
if (!$currentQ || ($draft['question'] ?? null) !== $currentQ['id']) $draft=null;
require __DIR__ . '/app/views.php';
