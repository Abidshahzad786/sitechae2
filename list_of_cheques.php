<?php
declare(strict_types=1);

// Prefer existing project connection.php if available
$__conn_included = false;
foreach ([__DIR__ . '/connection.php', __DIR__ . '/includes/connection.php', __DIR__ . '/../connection.php'] as $__cand) {
    if (file_exists($__cand)) { require_once $__cand; $__conn_included = true; break; }
}
// Fallback local connector only if no PDO is available
if (!isset($pdo) || !(class_exists('PDO') && ($pdo instanceof PDO))) {
    if (!function_exists('get_pdo')) {
        $dbHelper = __DIR__ . '/includes/db.php';
        if (file_exists($dbHelper)) { require_once $dbHelper; }
    }
}

// Utilities
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function parse_date_input(?string $value): ?string {
    if ($value === null || trim($value) === '') { return null; }
    $value = trim($value);
    // Accept either DD/MON/YYYY (e.g., 05/JAN/2025) or ISO YYYY-MM-DD
    $dt = DateTime::createFromFormat('d/M/Y', strtoupper($value));
    if ($dt instanceof DateTime) { return $dt->format('Y-m-d'); }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if ($dt instanceof DateTime) { return $dt->format('Y-m-d'); }
    return null;
}

function format_date_display(?string $value): string {
    if ($value === null || $value === '' || $value === '0000-00-00') { return ''; }
    try {
        $dt = new DateTime($value);
        return strtoupper($dt->format('d/M/Y'));
    } catch (Throwable $e) {
        return h($value);
    }
}

function format_amount(?string $value): string {
    if ($value === null || $value === '') { return '0'; }
    return number_format((float)$value, 2, '.', ',');
}

$isMysqli = isset($conn) && ($conn instanceof mysqli);
$pdo = (isset($pdo) && class_exists('PDO') && ($pdo instanceof PDO)) ? $pdo : null;
try {
    if (!$isMysqli && !($pdo instanceof PDO)) {
        if (function_exists('get_pdo')) {
            $pdo = get_pdo();
        } else {
            throw new RuntimeException('No DB connection available. Provide mysqli $conn in connection.php or PDO via $pdo/get_pdo().');
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Error</title></head><body>'; 
    echo '<pre>' . h($e->getMessage()) . '</pre>'; 
    echo '</body></html>';
    exit;
}

// Read filters
$selectedPartyId = isset($_GET['party_id']) && $_GET['party_id'] !== '' ? (string)$_GET['party_id'] : '';
$selectedChequeId = isset($_GET['cheque_id']) && $_GET['cheque_id'] !== '' ? (string)$_GET['cheque_id'] : '';
$dateFromRaw = isset($_GET['date_from']) ? (string)$_GET['date_from'] : '';
$dateToRaw   = isset($_GET['date_to'])   ? (string)$_GET['date_to']   : '';

$dateFrom = parse_date_input($dateFromRaw);
$dateTo   = parse_date_input($dateToRaw);
$shouldRun = isset($_GET['run']) && $_GET['run'] === '1';

// Fetch dropdown data
$parties = [];
if ($isMysqli) {
    $res = $conn->query('SELECT party_id, party_name FROM parties ORDER BY party_name');
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) { $parties[] = $row; }
        $res->free();
    }
} else {
    try {
        $stmt = $pdo->query('SELECT party_id, party_name FROM parties ORDER BY party_name');
        $parties = $stmt->fetchAll();
    } catch (Throwable $e) {
        $parties = [];
    }
}

$cheques = [];
if ($isMysqli) {
    $res = $conn->query('SELECT cheque_id, cheque_name FROM cheque_master ORDER BY cheque_name');
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) { $cheques[] = $row; }
        $res->free();
    } else {
        $res2 = $conn->query('SELECT DISTINCT cheque_id, CAST(cheque_id AS CHAR) AS cheque_name FROM transactions ORDER BY cheque_id');
        if ($res2 instanceof mysqli_result) {
            while ($row = $res2->fetch_assoc()) { $cheques[] = $row; }
            $res2->free();
        }
    }
} else {
    try {
        // Prefer cheque_master if available
        $stmt = $pdo->query('SELECT cheque_id, cheque_name FROM cheque_master ORDER BY cheque_name');
        $cheques = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Fallback: distinct IDs from transactions
        try {
            $stmt = $pdo->query('SELECT DISTINCT cheque_id, CAST(cheque_id AS CHAR) AS cheque_name FROM transactions ORDER BY cheque_id');
            $cheques = $stmt->fetchAll();
        } catch (Throwable $e2) {
            $cheques = [];
        }
    }
}

// Build query
$params = [];
$wheres = [];
$bindTypes = '';
$bindValues = [];

if ($selectedPartyId !== '') {
    $wheres[] = 't.party_id = :party_id';
    $params[':party_id'] = $selectedPartyId;
    $bindTypes .= 'i';
    $bindValues[] = (int)$selectedPartyId;
}

if ($selectedChequeId !== '') {
    $wheres[] = 't.cheque_id = :cheque_id';
    $params[':cheque_id'] = $selectedChequeId;
    $bindTypes .= 'i';
    $bindValues[] = (int)$selectedChequeId;
}

if ($dateFrom !== null) {
    $wheres[] = 't.transaction_date >= :date_from';
    $params[':date_from'] = $dateFrom;
    $bindTypes .= 's';
    $bindValues[] = $dateFrom;
}

if ($dateTo !== null) {
    $wheres[] = 't.transaction_date <= :date_to';
    $params[':date_to'] = $dateTo;
    $bindTypes .= 's';
    $bindValues[] = $dateTo;
}

$whereSql = count($wheres) ? ('WHERE ' . implode(' AND ', $wheres)) : '';

$sql = "
    SELECT
        t.transaction_id,
        t.transaction_date,
        p.party_name,
        t.cheque_id,
        cm.cheque_name,
        t.cheque_date,
        t.cheque_amount,
        t.acc_payee_only,
        t.remarks
    FROM transactions t
    JOIN parties p ON p.party_id = t.party_id
    LEFT JOIN cheque_master cm ON cm.cheque_id = t.cheque_id
    {$whereSql}
    ORDER BY t.transaction_date DESC, t.transaction_id DESC
";

// Helper to bind params in mysqli dynamically
if (!function_exists('mysqli_stmt_bind_params_dyn')) {
    function mysqli_stmt_bind_params_dyn(mysqli_stmt $stmt, string $types, array $values): void {
        if ($types === '' || count($values) === 0) { return; }
        $refs = [];
        foreach ($values as $k => $v) { $refs[$k] = &$values[$k]; }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $shouldRun) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cheques_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Transaction Date', 'Party', 'Cheque Name', 'Cheque ID', 'Cheque Date', 'Amount', 'A/C Payee Only', 'Remarks']);
    if ($isMysqli) {
        $sqlM = str_replace([':party_id', ':cheque_id', ':date_from', ':date_to'], ['?','?','?','?'], $sql);
        $stmt = $conn->prepare($sqlM);
        if ($stmt) {
            mysqli_stmt_bind_params_dyn($stmt, $bindTypes, $bindValues);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res instanceof mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    $chequeName = $row['cheque_name'] ?? '';
                    if ($chequeName === '' || $chequeName === null) { $chequeName = (string)($row['cheque_id'] ?? ''); }
                    fputcsv($out, [
                        format_date_display($row['transaction_date'] ?? null),
                        $row['party_name'] ?? '',
                        $chequeName,
                        (string)($row['cheque_id'] ?? ''),
                        format_date_display($row['cheque_date'] ?? null),
                        format_amount((string)($row['cheque_amount'] ?? '')),
                        ((string)($row['acc_payee_only'] ?? '')) === 'Y' ? 'Yes' : 'No',
                        (string)($row['remarks'] ?? ''),
                    ]);
                }
                $res->free();
            }
            $stmt->close();
        }
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            $chequeName = $row['cheque_name'] ?? '';
            if ($chequeName === '' || $chequeName === null) { $chequeName = (string)($row['cheque_id'] ?? ''); }
            fputcsv($out, [
                format_date_display($row['transaction_date'] ?? null),
                $row['party_name'] ?? '',
                $chequeName,
                (string)($row['cheque_id'] ?? ''),
                format_date_display($row['cheque_date'] ?? null),
                format_amount((string)($row['cheque_amount'] ?? '')),
                ((string)($row['acc_payee_only'] ?? '')) === 'Y' ? 'Yes' : 'No',
                (string)($row['remarks'] ?? ''),
            ]);
        }
    }
    fclose($out);
    exit;
}

// Fetch page rows only when filters submitted
$rows = [];
if ($shouldRun) {
    if ($isMysqli) {
        $sqlM = str_replace([':party_id', ':cheque_id', ':date_from', ':date_to'], ['?','?','?','?'], $sql);
        $stmt = $conn->prepare($sqlM);
        if ($stmt) {
            mysqli_stmt_bind_params_dyn($stmt, $bindTypes, $bindValues);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res instanceof mysqli_result) {
                while ($row = $res->fetch_assoc()) { $rows[] = $row; }
                $res->free();
            }
            $stmt->close();
        }
    } else {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
    }
}

?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>List of Cheques</title>
    <link rel="stylesheet" href="assets/theme.css">
    <style>
      @media print {
        .no-print { display: none !important; }
        body, .app-theme { background: #fff !important; color: #000 !important; }
        .app-card { box-shadow: none !important; border: none !important; }
      }
      /* Responsive filters: stacked on mobile, wrap on desktop to avoid overlap */
      .filters-bar { display: grid; gap: 12px; align-items: end; }
      .filters-actions { display: flex; gap: 8px; align-items: flex-end; }
      @media (min-width: 992px) {
        .filters-bar { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
        .filters-bar > .field { flex: 0 0 auto; }
        /* Buttons occupy full width of second row on desktop to avoid cramp */
        .filters-actions { flex-basis: 100%; margin-left: 0; }
        /* Reasonable control widths on desktop so items fit and wrap if needed */
        .filters-bar .field select,
        .filters-bar .field input[type="text"] { width: 220px; max-width: 100%; }
        .filters-bar .field-date input[type="text"] { width: 160px; max-width: 100%; }
      }
    </style>
  </head>
  <body class="app-theme">
    <div class="app-card full">
      <div class="app-header">
        <div class="brand">
          <div class="logo"></div>
          <div class="app-title">List of Cheques</div>
          <div class="app-muted">Filter and export cheques based on transactions</div>
        </div>
        <div class="actions no-print" style="display:flex; gap:8px; align-items:center;">
          <a class="app-logout" href="index.php">Home</a>
        </div>
      </div>
      <div class="app-content">
        <form class="form-grid filters-bar no-print" method="get" action="">
          <input type="hidden" name="run" value="1" />
          <div class="field field-party">
            <label for="party_id">Party</label>
            <select name="party_id" id="party_id">
              <option value="">All Parties</option>
              <?php foreach ($parties as $p): ?>
                <option value="<?= h((string)$p['party_id']) ?>" <?= $selectedPartyId === (string)$p['party_id'] ? 'selected' : '' ?>><?= h((string)$p['party_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field field-cheque">
            <label for="cheque_id">Cheque Name</label>
            <select name="cheque_id" id="cheque_id">
              <option value="">All Cheques</option>
              <?php foreach ($cheques as $c): ?>
                <option value="<?= h((string)$c['cheque_id']) ?>" <?= $selectedChequeId === (string)$c['cheque_id'] ? 'selected' : '' ?>><?= h((string)$c['cheque_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field field-date">
            <label for="date_from">Date From (DD/MON/YYYY)</label>
            <input type="text" name="date_from" id="date_from" placeholder="DD/MON/YYYY" value="<?= h($dateFromRaw) ?>" />
            <input type="date" id="date_from_iso" style="display:none" />
          </div>

          <div class="field field-date">
            <label for="date_to">Date To (DD/MON/YYYY)</label>
            <input type="text" name="date_to" id="date_to" placeholder="DD/MON/YYYY" value="<?= h($dateToRaw) ?>" />
            <input type="date" id="date_to_iso" style="display:none" />
          </div>

          <div class="filters-actions">
            <button type="submit" class="app-btn">Apply Filters</button>
            <a class="app-link" href="list_of_cheques.php">Reset</a>
            <button type="button" class="app-btn btn-print" onclick="window.print()">Print</button>
            <a class="app-btn secondary" id="exportCsvBtn" href="#">Export CSV</a>
          </div>
        </form>

        <?php if (!$shouldRun): ?>
          <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
            </svg>
            <div>Select filters and click Apply to view results.</div>
          </div>
        <?php elseif (count($rows) === 0): ?>
          <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
            </svg>
            <div>No results found. Adjust filters and try again.</div>
          </div>
        <?php else: ?>
          <div class="app-panel" style="overflow-x:auto;">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Transaction Date</th>
                  <th>Party</th>
                  <th>Cheque Name</th>
                  <th>Cheque ID</th>
                  <th>Cheque Date</th>
                  <th style="text-align:right;">Amount</th>
                  <th>A/C Payee Only</th>
                  <th>Remarks</th>
                </tr>
              </thead>
              <tbody>
                <?php $i = 0; $sum = 0.0; foreach ($rows as $r): $i++; $sum += (float)($r['cheque_amount'] ?? 0); ?>
                  <tr>
                    <td><?= $i ?></td>
                    <td class="date-cell"><?= h(format_date_display($r['transaction_date'] ?? null)) ?></td>
                    <td><?= h((string)($r['party_name'] ?? '')) ?></td>
                    <td><?= h((string)($r['cheque_name'] ?? ($r['cheque_id'] ?? ''))) ?></td>
                    <td><?= h((string)($r['cheque_id'] ?? '')) ?></td>
                    <td class="date-cell"><?= h(format_date_display($r['cheque_date'] ?? null)) ?></td>
                    <td class="amount-cell" style="text-align:right;"><?= h(format_amount((string)($r['cheque_amount'] ?? ''))) ?></td>
                    <td>
                      <?php $ap = (string)($r['acc_payee_only'] ?? ''); ?>
                      <span class="status-badge <?= $ap === 'Y' ? 'status-yes' : 'status-no' ?>"><?= $ap === 'Y' ? 'Yes' : 'No' ?></span>
                    </td>
                    <td><?= h((string)($r['remarks'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <th colspan="6" style="text-align:right;">Total</th>
                  <th style="text-align:right;" class="amount-cell"><?= h(number_format($sum, 2, '.', ',')) ?></th>
                  <th colspan="2"></th>
                </tr>
              </tfoot>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <script>
      function toMonAbbr(dateObj) {
        const months = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
        const d = String(dateObj.getDate()).padStart(2,'0');
        const m = months[dateObj.getMonth()];
        const y = dateObj.getFullYear();
        return `${d}/${m}/${y}`;
      }

      function isoToDisplay(iso) {
        if (!iso) return '';
        const parts = iso.split('-');
        if (parts.length !== 3) return iso;
        const d = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        if (isNaN(d.getTime())) return '';
        return toMonAbbr(d);
      }

      function displayToIso(display) {
        if (!display) return '';
        const m = display.trim().toUpperCase().match(/^([0-3]\d)\/([A-Z]{3})\/(\d{4})$/);
        if (!m) return '';
        const day = Number(m[1]);
        const monMap = {JAN:0,FEB:1,MAR:2,APR:3,MAY:4,JUN:5,JUL:6,AUG:7,SEP:8,OCT:9,NOV:10,DEC:11};
        const mon = monMap[m[2]];
        const year = Number(m[3]);
        if (mon === undefined) return '';
        const d = new Date(year, mon, day);
        if (isNaN(d.getTime())) return '';
        const mm = String(d.getMonth() + 1).padStart(2,'0');
        const dd = String(d.getDate()).padStart(2,'0');
        return `${year}-${mm}-${dd}`;
      }

      function wireDatePair(textId, hiddenId) {
        const txt = document.getElementById(textId);
        const hid = document.getElementById(hiddenId);
        if (!txt || !hid) return;
        // Initialize from existing value if ISO in text
        const iso = displayToIso(txt.value);
        if (iso) hid.value = iso; else if (txt.value.match(/^\d{4}-\d{2}-\d{2}$/)) { hid.value = txt.value; txt.value = isoToDisplay(txt.value); }
        // Clicking the text input opens the native picker
        txt.addEventListener('click', () => {
          if (hid.showPicker) {
            hid.showPicker();
          } else {
            hid.focus(); hid.click();
          }
        });
        hid.addEventListener('change', () => {
          txt.value = isoToDisplay(hid.value);
        });
        // Keep hidden ISO in sync on manual edits
        txt.addEventListener('blur', () => {
          const v = displayToIso(txt.value);
          if (v) hid.value = v;
        });
        // On form submit, ensure text holds the display string (server accepts both)
        txt.form?.addEventListener('submit', () => {
          if (!txt.value && hid.value) txt.value = isoToDisplay(hid.value);
        });
      }

      wireDatePair('date_from', 'date_from_iso');
      wireDatePair('date_to', 'date_to_iso');

      // Export CSV button uses current filters
      (function() {
        const btn = document.getElementById('exportCsvBtn');
        if (!btn) return;
        const params = new URLSearchParams(window.location.search);
        params.set('run', '1');
        params.set('export', 'csv');
        btn.href = `${window.location.pathname}?${params.toString()}`;
      })();
    </script>
  </body>
</html>

