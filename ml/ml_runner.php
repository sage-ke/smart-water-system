<?php
/*
 * ml_runner.php — ML Engine Runner
 * Admin-only browser trigger for the Python ML engine.
 * Visit: http://localhost/smart_water/ml_runner.php
 */
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403); die('Admin only');
}

// ── Find Python on Windows ────────────────────────────────────
function find_python(): string {
    $candidates = [
        'python',   // in PATH
        'py',       // Python Launcher
        'C:\\Python312\\python.exe',
        'C:\\Python311\\python.exe',
        'C:\\Python310\\python.exe',
        'C:\\Python39\\python.exe',
        getenv('LOCALAPPDATA') . '\\Programs\\Python\\Python312\\python.exe',
        getenv('LOCALAPPDATA') . '\\Programs\\Python\\Python311\\python.exe',
        getenv('LOCALAPPDATA') . '\\Programs\\Python\\Python310\\python.exe',
        getenv('LOCALAPPDATA') . '\\Microsoft\\WindowsApps\\python.exe',
    ];
    foreach ($candidates as $p) {
        if (!$p) continue;
        // For short names check with where
        if (!str_contains($p, '\\')) {
            exec("where $p 2>nul", $out, $code);
            if ($code === 0 && !empty($out)) return $p;
        } elseif (file_exists($p)) {
            return $p;
        }
    }
    return '';
}

$python  = find_python();
$script  = __DIR__ . '\\prediction_engine.py';
$logdir  = __DIR__ . '\\logs';
$logfile = $logdir . '\\ml_engine.log';
$ran     = false;
$output  = [];
$error   = '';

if (!is_dir($logdir)) mkdir($logdir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['run'] ?? '') === '1') {
    if (!$python) {
        $error = 'Python not found. Install Python from python.org and check "Add to PATH".';
    } elseif (!file_exists($script)) {
        $error = 'prediction_engine.py not found at: ' . $script;
    } else {
        $cmd = '"' . $python . '" "' . $script . '" 2>&1';
        exec($cmd, $output, $code);
        $log = date('[Y-m-d H:i:s]') . " exit=$code\n" . implode("\n", $output) . "\n\n";
        file_put_contents($logfile, $log, FILE_APPEND);
        $ran  = true;
        $error = $code !== 0 ? 'ML engine exited with code ' . $code : '';
    }
}

$log_tail = '';
if (file_exists($logfile)) {
    $lines = file($logfile);
    $log_tail = implode('', array_slice($lines, -40)); // last 40 lines
}

require_once __DIR__ . '/sidebar.php';
?>
<style>
.run-card{background:var(--card);border:1px solid var(--border);border-radius:14px;
          padding:1.5rem;margin-bottom:1rem}
.log-box{background:#0a0f1a;border:1px solid var(--border);border-radius:10px;
         padding:1rem;font-family:'Courier New',monospace;font-size:.75rem;
         color:#94a3b8;white-space:pre-wrap;max-height:400px;overflow-y:auto;
         line-height:1.6}
.out-line{color:#34d399}.err-line{color:#f87171}
</style>

<div class="section-title">⚙️ ML Engine Runner</div>

<?php if($error): ?>
<div style="background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);
     border-radius:10px;padding:12px 16px;color:#f87171;margin-bottom:1rem;font-size:.88rem">
    ❌ <?= htmlspecialchars($error) ?>
    <?php if(str_contains($error,'Python')): ?>
    <div style="margin-top:8px;font-size:.78rem;color:var(--muted)">
        Install Python from
        <a href="https://python.org/downloads" target="_blank" style="color:var(--blue)">
        python.org/downloads</a> and check <strong>"Add Python to PATH"</strong> during install.
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if($ran && !$error): ?>
<div style="background:rgba(52,211,153,.1);border:1px solid rgba(52,211,153,.3);
     border-radius:10px;padding:12px 16px;color:#34d399;margin-bottom:1rem;font-size:.88rem">
    ✅ ML engine completed successfully — <?= count($output) ?> output lines
</div>
<?php endif; ?>

<div class="run-card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
        <div>
            <div style="font-weight:700;margin-bottom:4px">Python ML Engine</div>
            <div style="font-size:.8rem;color:var(--muted)">
                Script: <code style="color:var(--blue)">prediction_engine.py</code><br>
                Python: <code style="color:<?= $python?'var(--green)':'var(--red)'?>">
                    <?= $python ?: 'Not found' ?>
                </code>
            </div>
        </div>
        <form method="POST" style="margin:0">
            <input type="hidden" name="run" value="1">
            <button type="submit" <?= !$python?'disabled':'' ?>
                style="padding:10px 24px;background:<?= $python?'linear-gradient(135deg,#0ea5e9,#06b6d4)':'#334155' ?>;
                       border:none;border-radius:8px;color:#fff;font-weight:700;
                       font-size:.9rem;cursor:<?= $python?'pointer':'not-allowed' ?>">
                ▶ Run ML Engine Now
            </button>
        </form>
    </div>
</div>

<?php if($ran && $output): ?>
<div class="section-title">📤 Output</div>
<div class="log-box"><?php
    foreach($output as $line) {
        $cls = (str_contains($line,'Error') || str_contains($line,'error') || str_contains($line,'failed'))
             ? 'err-line' : 'out-line';
        echo '<span class="'.$cls.'">'.htmlspecialchars($line)."</span>\n";
    }
?></div>
<?php endif; ?>

<?php if($log_tail): ?>
<div class="section-title" style="margin-top:1.5rem">
    📄 Recent Log (last 40 lines)
    <a href="logs/ml_engine.log" target="_blank"
       style="font-size:.72rem;color:var(--blue);float:right">Open full log ↗</a>
</div>
<div class="log-box"><?= htmlspecialchars($log_tail) ?></div>
<?php else: ?>
<div style="color:var(--muted);font-size:.82rem;margin-top:1rem">
    No log file yet — run the engine above to generate logs.
</div>
<?php endif; ?>

</main></body></html>