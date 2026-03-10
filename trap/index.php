<?php
/**
 * The Honeypo(e)t — Trap Handler
 *
 * Catches every incoming request, records it to SQLite with GeoIP enrichment
 * and attack categorization. Every path is interesting data, nothing gets blocked.
 *
 * "Every knock on the door gets a poem."
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration (passed via FastCGI params, with sane defaults)
// ---------------------------------------------------------------------------

$DB_PATH       = $_SERVER['TRAP_DB_PATH']       ?? 'honeypoet.db';
$GEOIP_CITY    = $_SERVER['GEOIP_CITY_DB_PATH'] ?? 'GeoLite2-City.mmdb';
$GEOIP_ASN     = $_SERVER['GEOIP_ASN_DB_PATH']  ?? 'GeoLite2-ASN.mmdb';
$MAX_BODY_SIZE = 4096; // 4KB — enough to capture login forms, truncate the rest

require_once __DIR__ . '/names.php';

// ---------------------------------------------------------------------------
// Capture request data
// ---------------------------------------------------------------------------

$source_ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$method       = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path         = $_SERVER['REQUEST_URI'] ?? '/';
$query_string = $_SERVER['QUERY_STRING'] ?? '';
$user_agent   = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Separate path from query string (REQUEST_URI includes both)
$parsed = parse_url($path);
$clean_path   = $parsed['path'] ?? '/';
$query_string = $parsed['query'] ?? $query_string;

// ---------------------------------------------------------------------------
// Request routing — handle /_api/* and gallery before capture
// ---------------------------------------------------------------------------

// API endpoint: return location data for the world map
if ($clean_path === '/_api/locations' && $method === 'GET') {
    try {
        $db = new PDO('sqlite:' . $DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA busy_timeout=3000');

        // Ensure lat/lng columns exist (migration may not have run yet)
        $columns = array_column($db->query('PRAGMA table_info(visits)')->fetchAll(), 'name');
        if (!in_array('latitude', $columns, true)) {
            $db->exec('ALTER TABLE visits ADD COLUMN latitude REAL');
        }
        if (!in_array('longitude', $columns, true)) {
            $db->exec('ALTER TABLE visits ADD COLUMN longitude REAL');
        }

        $rows = $db->query('
            SELECT latitude, longitude, COUNT(*) as count, country, city
            FROM visits
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL
            GROUP BY latitude, longitude
        ')->fetchAll();

        $locations = [];
        $total = 0;
        foreach ($rows as $row) {
            $locations[] = [(float) $row['latitude'], (float) $row['longitude'], (int) $row['count'], $row['country'] ?? '', $row['city'] ?? ''];
            $total += (int) $row['count'];
        }

        $since = $db->query('SELECT MIN(timestamp) as first FROM visits')->fetch();

        // Visitor count (table may not exist yet on first deploy)
        $visitor_count = 0;
        try {
            $vc = $db->query('SELECT COUNT(*) as cnt FROM visitors')->fetch();
            $visitor_count = (int) $vc['cnt'];
        } catch (\PDOException $e) {
            // visitors table doesn't exist yet — that's fine
        }

        header('Content-Type: application/json');
        header('Cache-Control: no-cache');
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            'locations'     => $locations,
            'total'         => $total,
            'visitor_count' => $visitor_count,
            'since'         => $since['first'] ?? null,
        ], JSON_UNESCAPED_SLASHES);
    } catch (\PDOException $e) {
        error_log("honeypoet: API error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal']);
    }
    exit;
}

// API endpoint: return recent visits for the live feed
if ($clean_path === '/_api/recent' && $method === 'GET') {
    try {
        $db = new PDO('sqlite:' . $DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA busy_timeout=3000');

        $rows = $db->query('
            SELECT v.id, v.timestamp, v.source_ip, v.country, v.city, v.path,
                   v.attack_category, v.response_type, v.response_content,
                   v.llm_generated, vis.name AS visitor_name,
                   vis.behavior AS visitor_behavior
            FROM visits v
            LEFT JOIN visitors vis ON v.visitor_id = vis.id
            ORDER BY v.id DESC
            LIMIT 30
        ')->fetchAll();

        // Mask IPs: 124.56.78.129 → 124.xxx.xxx.129
        foreach ($rows as &$row) {
            $parts = explode('.', $row['source_ip']);
            if (count($parts) === 4) {
                $row['source_ip'] = $parts[0] . '.xxx.xxx.' . $parts[3];
            } else {
                // IPv6 or unusual — just show first and last segment
                $row['source_ip'] = substr($row['source_ip'], 0, 4) . ':...:' . substr($row['source_ip'], -4);
            }
        }
        unset($row);

        header('Content-Type: application/json');
        header('Cache-Control: no-cache');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(['visits' => $rows], JSON_UNESCAPED_SLASHES);
    } catch (\PDOException $e) {
        error_log("honeypoet: API error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal']);
    }
    exit;
}

// API endpoint: return LLM poems with pagination
if ($clean_path === '/_api/poems' && $method === 'GET') {
    try {
        $db = new PDO('sqlite:' . $DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA busy_timeout=3000');

        $limit  = max(1, min(200, (int) ($_GET['limit']  ?? 50)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $country = isset($_GET['country']) ? trim($_GET['country']) : '';

        // Dedup subquery: keep newest visit per unique poem
        $dedup = 'SELECT MAX(id) FROM visits WHERE llm_generated = 1 GROUP BY response_content';

        $where = 'v.llm_generated = 1 AND v.id IN (' . $dedup . ')';
        $params = [];
        if ($country !== '') {
            $where .= ' AND v.country = ?';
            $params[] = $country;
        }

        // Total count
        $countStmt = $db->prepare('SELECT COUNT(*) FROM visits v WHERE ' . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Paginated rows
        $sql = 'SELECT v.id, v.timestamp, v.country, v.attack_category,
                       v.response_type, v.response_content, v.path,
                       vis.name AS visitor_name,
                       vis.behavior AS visitor_behavior
                FROM visits v
                LEFT JOIN visitors vis ON v.visitor_id = vis.id
                WHERE ' . $where . '
                ORDER BY v.id DESC
                LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: application/json');
        header('Cache-Control: no-cache');
        header('Access-Control-Allow-Origin: *');
        echo json_encode(['poems' => $rows, 'total' => $total], JSON_UNESCAPED_SLASHES);
    } catch (\PDOException $e) {
        error_log("honeypoet: API error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal']);
    }
    exit;
}

// API endpoint: museum stats — top visitors, weekly exhibits, zeitgeist
if ($clean_path === '/_api/museum' && $method === 'GET') {
    try {
        $db = new PDO('sqlite:' . $DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA busy_timeout=3000');

        // Top 10 countries by unique visitors
        $countries = $db->query('
            SELECT v.country, COUNT(DISTINCT v.visitor_id) as count
            FROM visits v
            WHERE v.country IS NOT NULL AND v.country != ""
              AND v.visitor_id IS NOT NULL
            GROUP BY v.country ORDER BY count DESC LIMIT 10
        ')->fetchAll();

        // Top 15 cities by unique visitors
        $cities = $db->query('
            SELECT v.city, v.country, COUNT(DISTINCT v.visitor_id) as count
            FROM visits v
            WHERE v.city IS NOT NULL AND v.city != ""
              AND v.visitor_id IS NOT NULL
            GROUP BY v.city, v.country ORDER BY count DESC LIMIT 15
        ')->fetchAll();

        // Weekly exhibits — best poems from last 4 weeks
        // "Best" = moderate length (80-400 chars), no repetition loops, random selection
        $exhibits = [];
        for ($w = 0; $w < 4; $w++) {
            $weekStart = date('Y-m-d', strtotime("monday this week -" . ($w * 7) . " days"));
            $weekEnd   = date('Y-m-d', strtotime("monday this week -" . (($w - 1) * 7) . " days"));
            $weekLabel = date('Y-\\WW', strtotime($weekStart));

            $stmt = $db->prepare('
                SELECT v.timestamp, v.country, v.city, v.attack_category,
                       v.response_content, v.response_type,
                       vis.name AS visitor_name, vis.behavior AS visitor_behavior
                FROM visits v
                LEFT JOIN visitors vis ON v.visitor_id = vis.id
                WHERE v.llm_generated = 1
                  AND v.response_content IS NOT NULL
                  AND LENGTH(v.response_content) BETWEEN 80 AND 400
                  AND v.response_content NOT LIKE "%write a %"
                  AND v.response_content NOT LIKE "%your poem%"
                  AND v.response_content NOT LIKE "%your task%"
                  AND v.timestamp >= :start
                  AND v.timestamp < :end
                  AND v.id IN (
                      SELECT MAX(id) FROM visits
                      WHERE llm_generated = 1
                      GROUP BY response_content
                  )
                ORDER BY RANDOM()
                LIMIT 5
            ');
            $stmt->execute([':start' => $weekStart, ':end' => $weekEnd]);
            $weekPoems = $stmt->fetchAll();
            if ($weekPoems) {
                $exhibits[$weekLabel] = $weekPoems;
            }
        }

        // Summary stats
        $total_visitors = 0;
        try {
            $total_visitors = (int) $db->query('SELECT COUNT(*) FROM visitors')->fetchColumn();
        } catch (\PDOException $e) {}
        $total_visits = (int) $db->query('SELECT COUNT(*) FROM visits')->fetchColumn();
        $total_poems  = (int) $db->query('SELECT COUNT(*) FROM visits WHERE llm_generated = 1')->fetchColumn();
        $since        = $db->query('SELECT MIN(timestamp) FROM visits')->fetchColumn();

        // Behaviors — count of visitors per behavior type
        $behaviors = [];
        try {
            $behaviors = $db->query('
                SELECT behavior, COUNT(*) as count
                FROM visitors
                WHERE behavior IS NOT NULL AND behavior != ""
                GROUP BY behavior ORDER BY count DESC
            ')->fetchAll();
        } catch (\PDOException $e) {}

        // Top categories by country — for top 5 countries, their top 3 attack categories
        $top_categories_by_country = [];
        $top5 = array_slice($countries, 0, 5);
        foreach ($top5 as $c) {
            $stmt = $db->prepare('
                SELECT v.attack_category as category, COUNT(DISTINCT v.visitor_id) as count
                FROM visits v
                WHERE v.country = :country
                  AND v.attack_category IS NOT NULL AND v.attack_category != ""
                  AND v.attack_category != "visitor"
                  AND v.visitor_id IS NOT NULL
                GROUP BY v.attack_category ORDER BY count DESC LIMIT 3
            ');
            $stmt->execute([':country' => $c['country']]);
            $top_categories_by_country[$c['country']] = $stmt->fetchAll();
        }

        // Hourly activity — visits per hour of day (0-23 UTC)
        $hourly_activity = $db->query('
            SELECT CAST(strftime("%H", timestamp) AS INTEGER) as hour, COUNT(*) as count
            FROM visits
            GROUP BY hour ORDER BY hour
        ')->fetchAll();

        // Avg visits per visitor by country — top 10 countries
        $avg_visits_by_country = $db->query('
            SELECT v.country,
                   ROUND(CAST(COUNT(*) AS REAL) / COUNT(DISTINCT v.visitor_id), 1) as avg_visits,
                   COUNT(DISTINCT v.visitor_id) as visitors
            FROM visits v
            WHERE v.country IS NOT NULL AND v.country != ""
              AND v.visitor_id IS NOT NULL
            GROUP BY v.country
            HAVING visitors >= 3
            ORDER BY visitors DESC LIMIT 10
        ')->fetchAll();

        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=300');
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            'countries'    => $countries,
            'cities'       => $cities,
            'exhibits'     => $exhibits,
            'total_visitors' => $total_visitors,
            'total_visits'   => $total_visits,
            'total_poems'    => $total_poems,
            'since'        => $since,
            'behaviors'    => $behaviors,
            'top_categories_by_country' => $top_categories_by_country,
            'hourly_activity' => $hourly_activity,
            'avg_visits_by_country' => $avg_visits_by_country,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (\PDOException $e) {
        error_log("honeypoet: museum API error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'internal']);
    }
    exit;
}

// ---------------------------------------------------------------------------
// Everything else is traffic — capture it (including gallery visitors)
// ---------------------------------------------------------------------------

// Capture all headers as JSON
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $header_name = str_replace('_', '-', substr($key, 5));
        $headers[$header_name] = $value;
    }
}
// Include Content-Type and Content-Length if present
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['CONTENT-TYPE'] = $_SERVER['CONTENT_TYPE'];
}
if (isset($_SERVER['CONTENT_LENGTH'])) {
    $headers['CONTENT-LENGTH'] = $_SERVER['CONTENT_LENGTH'];
}
$headers_json = json_encode($headers, JSON_UNESCAPED_SLASHES);

// Capture request body for POST/PUT/PATCH (truncated)
$body = null;
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $raw = file_get_contents('php://input');
    if ($raw !== false && strlen($raw) > 0) {
        $body = substr($raw, 0, $MAX_BODY_SIZE);
    }
}

// ---------------------------------------------------------------------------
// GeoIP enrichment
// ---------------------------------------------------------------------------

$country   = null;
$city      = null;
$latitude  = null;
$longitude = null;
$asn       = null;
$isp       = null;

// City + Country + Coordinates
if (file_exists($GEOIP_CITY)) {
    try {
        $city_reader = new MaxMind\Db\Reader($GEOIP_CITY);
        $record = $city_reader->get($source_ip);
        if ($record !== null) {
            $country   = $record['country']['iso_code'] ?? null;
            $city      = $record['city']['names']['en'] ?? null;
            $latitude  = $record['location']['latitude'] ?? null;
            $longitude = $record['location']['longitude'] ?? null;
        }
        $city_reader->close();
    } catch (\Exception $e) {
        // GeoIP lookup failure is not fatal — log and continue
        error_log("honeypoet: GeoIP city lookup failed for {$source_ip}: " . $e->getMessage());
    }
}

// ASN + ISP
if (file_exists($GEOIP_ASN)) {
    try {
        $asn_reader = new MaxMind\Db\Reader($GEOIP_ASN);
        $record = $asn_reader->get($source_ip);
        if ($record !== null) {
            $asn = isset($record['autonomous_system_number']) ? (string) $record['autonomous_system_number'] : null;
            $isp = $record['autonomous_system_organization'] ?? null;
        }
        $asn_reader->close();
    } catch (\Exception $e) {
        error_log("honeypoet: GeoIP ASN lookup failed for {$source_ip}: " . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Attack categorization
// ---------------------------------------------------------------------------

/**
 * Pattern-match the request into an attack category.
 * Order matters — first match wins, so put specific patterns before generic ones.
 */
function categorize_attack(string $path, string $method, ?string $body, string $query_string): string
{
    $path_lower  = strtolower($path);
    $query_lower = strtolower($query_string);

    // WordPress probes
    if (preg_match('#/(wp-login|wp-admin|wp-content|wp-includes|wp-config|xmlrpc|wp-cron|wp-json)#i', $path_lower)) {
        return 'wordpress';
    }

    // Upload endpoint exploits (KCFinder, elFinder, file managers — before webshell)
    if (preg_match('#/(kcfinder|elfinder|filemanager|ckeditor/.*/upload|plupload|uploadify)/#i', $path_lower)) {
        return 'upload_exploit';
    }

    // Webshell / backdoor hunting (before admin_panel — some paths contain "admin")
    if (preg_match('#/(shell|cmd|c99|r57|webshell|backdoor|up|upload|fileupload|xmr|rip|inputs|adminfuns|ioxi)\.php#i', $path_lower)) {
        return 'webshell';
    }
    if (preg_match('#/\.trash\d*/|/\.well-known/logs\d*/|/wk/index\.php|/function/function\.php#i', $path_lower)) {
        return 'webshell';
    }
    if (preg_match('#/wp-content/(uploads|themes|plugins)/[^/]+\.(php|phtml)$#i', $path_lower)) {
        return 'webshell';
    }

    // Environment file hunters (secrets, credentials)
    if (preg_match('#/\.env(\.|$)|/\.aws|/\.docker|/\.ssh|/\.bash|/\.htaccess|/\.htpasswd#i', $path_lower)) {
        return 'env_file';
    }

    // Version control / dev tool leaks (split from env_file)
    if (preg_match('#/\.git(/|$)|/\.svn(/|$)|/\.hg(/|$)|/\.DS_Store|/\.vscode(/|$)|/\.idea(/|$)|/\.aider#i', $path_lower)) {
        return 'vcs_leak';
    }

    // Admin panel fishers
    if (preg_match('#/(phpmyadmin|adminer|admin|cpanel|webmail|manager|console|dashboard|panel)(/|$)#i', $path_lower)) {
        return 'admin_panel';
    }

    // Path traversal
    if (str_contains($path, '..') || str_contains($query_string, '..')) {
        return 'path_traversal';
    }

    // SQL injection probes (in path or query string)
    if (preg_match('/(\bunion\b.*\bselect\b|\bor\b\s+\d+=\d+|\'.*--|;.*drop\b|benchmark\s*\()/i', $path . $query_string)) {
        return 'sqli_probe';
    }
    if (preg_match('/(\bunion\b|\bselect\b.*\bfrom\b|%27|%22|0x[0-9a-f]+)/i', $query_lower)) {
        return 'sqli_probe';
    }

    // CMS fingerprinting (reading the doormat before trying the door)
    if (preg_match('#^/(robots\.txt|humans\.txt|ads\.txt|security\.txt|license\.txt|readme\.html|CHANGELOG\.txt|INSTALL\.txt|UPDATE\.txt|sitemap\.xml|crossdomain\.xml|web\.config)$#i', $path_lower)) {
        return 'cms_fingerprint';
    }
    if (preg_match('#^/\.well-known/security\.txt$#i', $path_lower)) {
        return 'cms_fingerprint';
    }

    // API endpoint probes (widened: actuator, metrics, openid)
    if (preg_match('#^/(api|graphql|rest|v[0-9]+|swagger|openapi|actuator|metrics|sdk|api-docs)(/|$|\.)#i', $path_lower)) {
        return 'api_probe';
    }
    if (preg_match('#/\.well-known/openid-configuration$#i', $path_lower)) {
        return 'api_probe';
    }

    // IoT / appliance exploits (routers, cameras, network devices)
    if (preg_match('#/(cgi-bin|HNAP1|goform|stssys\.htm|currentsetting\.htm)(/|$)#i', $path_lower)) {
        return 'iot_exploit';
    }
    if (preg_match('#/update/picture\.cgi|/cgi-bin/(luci|authLogin\.cgi)#i', $path_lower)) {
        return 'iot_exploit';
    }

    // Dev tools (widened: pi.php, p.php, php.php, pinfo.php, _profiler)
    if (preg_match('#/(\.xdebug|debug|trace|profiler|_profiler|server-status|server-info|phpinfo|phpversion|test\.php|info\.php|pi\.php|p\.php|php\.php|pinfo\.php|i\.php)(/|$)#i', $path_lower)) {
        return 'dev_tools';
    }
    if (str_contains($query_lower, 'xdebug') || str_contains($query_lower, 'phpstorm')) {
        return 'dev_tools';
    }

    // Config file probes
    if (preg_match('#/(config|configuration|settings|database|credentials|backup|dump|db)\.(php|json|yml|yaml|xml|ini|conf|bak|sql|gz|zip|tar)#i', $path_lower)) {
        return 'config_probe';
    }
    if (preg_match('#/(composer\.(json|lock)|package\.json|Gemfile|requirements\.txt|Dockerfile|docker-compose)#i', $path_lower)) {
        return 'config_probe';
    }

    // Multi-protocol fingerprinting (non-HTTP protocols over HTTP port)
    if ($body !== null && (
        str_contains($body, 'SSH-2.0') ||
        str_contains($body, "\x07version\x04bind") ||
        str_contains($body, 'admin.$cmd') ||
        str_contains($body, "\x03\x00\x00\x13\x0E\xE0") ||
        preg_match('/^[\x00-\x1f\x80-\xff]{4,}/', $body)
    )) {
        return 'multi_protocol';
    }

    // Credential submissions (POST to login-like paths)
    if ($method === 'POST' && preg_match('#/(login|signin|auth|authenticate|session|token|oauth|register|signup)#i', $path_lower)) {
        return 'credential_submit';
    }

    // Generic scan (everything else that's not just /)
    if ($path !== '/' || $method !== 'GET') {
        return 'generic_scan';
    }

    // Legitimate-looking visit to /
    return 'visitor';
}

$attack_category = categorize_attack($clean_path, $method, $body, $query_string);

// ---------------------------------------------------------------------------
// Credential extraction
// ---------------------------------------------------------------------------

$credential_username = null;
$credential_password = null;

if ($attack_category === 'credential_submit' || ($method === 'POST' && preg_match('#/(wp-login|login|signin|auth)#i', $clean_path))) {
    // Try form data first
    $credential_username = $_POST['username'] ?? $_POST['user'] ?? $_POST['login'] ?? $_POST['email'] ?? $_POST['log'] ?? null;
    $credential_password = $_POST['password'] ?? $_POST['pass'] ?? $_POST['passwd'] ?? $_POST['pwd'] ?? null;

    // Try JSON body if form data didn't work
    if ($credential_username === null && $body !== null) {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $credential_username = $json['username'] ?? $json['user'] ?? $json['login'] ?? $json['email'] ?? null;
            $credential_password = $json['password'] ?? $json['pass'] ?? $json['passwd'] ?? $json['pwd'] ?? null;
        }
    }

    // Truncate credentials to prevent abuse
    if ($credential_username !== null) {
        $credential_username = substr((string) $credential_username, 0, 255);
    }
    if ($credential_password !== null) {
        $credential_password = substr((string) $credential_password, 0, 255);
    }
}

// ---------------------------------------------------------------------------
// SQLite persistence
// ---------------------------------------------------------------------------

try {
    $db = new PDO('sqlite:' . $DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // WAL mode for concurrent reads + writes
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA busy_timeout=3000');

    // Create table if not exists (includes future poet-layer columns as nullable)
    $db->exec('CREATE TABLE IF NOT EXISTS visits (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        timestamp           TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\')),
        source_ip           TEXT NOT NULL,
        country             TEXT,
        city                TEXT,
        asn                 TEXT,
        isp                 TEXT,
        method              TEXT NOT NULL,
        path                TEXT NOT NULL,
        query_string        TEXT,
        headers             TEXT,
        body                TEXT,
        user_agent          TEXT,
        attack_category     TEXT NOT NULL,
        credential_username TEXT,
        credential_password TEXT,
        response_type       TEXT,
        response_content    TEXT,
        llm_generated       INTEGER,
        swarm_id            TEXT
    )');

    // Schema migration: add lat/lng columns if missing
    $columns = array_column($db->query('PRAGMA table_info(visits)')->fetchAll(), 'name');
    if (!in_array('latitude', $columns, true)) {
        $db->exec('ALTER TABLE visits ADD COLUMN latitude REAL');
    }
    if (!in_array('longitude', $columns, true)) {
        $db->exec('ALTER TABLE visits ADD COLUMN longitude REAL');
    }

    // Schema migration: add visitor_id column if missing
    if (!in_array('visitor_id', $columns, true)) {
        $db->exec('ALTER TABLE visits ADD COLUMN visitor_id INTEGER');
    }

    // Visitors table — persistent names for unique fingerprints
    $db->exec('CREATE TABLE IF NOT EXISTS visitors (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        fingerprint TEXT NOT NULL UNIQUE,
        name        TEXT NOT NULL,
        first_seen  TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\')),
        last_seen   TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\')),
        visit_count INTEGER NOT NULL DEFAULT 1
    )');

    // Schema migration: add behavior column to visitors if missing
    $vis_columns = array_column($db->query('PRAGMA table_info(visitors)')->fetchAll(), 'name');
    if (!in_array('behavior', $vis_columns, true)) {
        $db->exec('ALTER TABLE visitors ADD COLUMN behavior TEXT');
    }

    // Indexes for common queries
    $db->exec('CREATE INDEX IF NOT EXISTS idx_visits_timestamp ON visits (timestamp)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_visits_source_ip ON visits (source_ip)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_visits_attack_category ON visits (attack_category)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_visits_country ON visits (country)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_visits_coords ON visits (latitude, longitude)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_visits_visitor_id ON visits (visitor_id)');

    // Insert the visit
    $stmt = $db->prepare('INSERT INTO visits (
        source_ip, country, city, asn, isp,
        method, path, query_string, headers, body, user_agent,
        attack_category, credential_username, credential_password,
        latitude, longitude
    ) VALUES (
        :source_ip, :country, :city, :asn, :isp,
        :method, :path, :query_string, :headers, :body, :user_agent,
        :attack_category, :credential_username, :credential_password,
        :latitude, :longitude
    )');

    $stmt->execute([
        ':source_ip'           => $source_ip,
        ':country'             => $country,
        ':city'                => $city,
        ':asn'                 => $asn,
        ':isp'                 => $isp,
        ':method'              => $method,
        ':path'                => $clean_path,
        ':query_string'        => $query_string ?: null,
        ':headers'             => $headers_json,
        ':body'                => $body,
        ':user_agent'          => $user_agent ?: null,
        ':attack_category'     => $attack_category,
        ':credential_username' => $credential_username,
        ':credential_password' => $credential_password,
        ':latitude'            => $latitude,
        ':longitude'           => $longitude,
    ]);

    $visit_id = $db->lastInsertId();

    // --- Visitor lookup / upsert ---
    $fingerprint = md5($source_ip . '|' . $user_agent);
    $now = gmdate('Y-m-d\TH:i:s\Z');

    // Try to find existing visitor
    $vis_stmt = $db->prepare('SELECT id FROM visitors WHERE fingerprint = :fp');
    $vis_stmt->execute([':fp' => $fingerprint]);
    $visitor = $vis_stmt->fetch();

    if ($visitor) {
        $visitor_id_fk = (int) $visitor['id'];
        $db->prepare('UPDATE visitors SET last_seen = :now, visit_count = visit_count + 1 WHERE id = :id')
            ->execute([':now' => $now, ':id' => $visitor_id_fk]);
    } else {
        // Assign next name based on current visitor count
        $count_row = $db->query('SELECT COUNT(*) as cnt FROM visitors')->fetch();
        $position = (int) $count_row['cnt'];
        $name = assign_visitor_name($position);

        try {
            $ins = $db->prepare('INSERT INTO visitors (fingerprint, name, first_seen, last_seen) VALUES (:fp, :name, :now, :now)');
            $ins->execute([':fp' => $fingerprint, ':name' => $name, ':now' => $now]);
            $visitor_id_fk = (int) $db->lastInsertId();
        } catch (\PDOException $race) {
            // UNIQUE constraint — another request beat us; fetch it
            $vis_stmt->execute([':fp' => $fingerprint]);
            $visitor = $vis_stmt->fetch();
            $visitor_id_fk = (int) $visitor['id'];
            $db->prepare('UPDATE visitors SET last_seen = :now, visit_count = visit_count + 1 WHERE id = :id')
                ->execute([':now' => $now, ':id' => $visitor_id_fk]);
        }
    }

    // Link visit to visitor
    $db->prepare('UPDATE visits SET visitor_id = :vid WHERE id = :id')
        ->execute([':vid' => $visitor_id_fk, ':id' => $visit_id]);
} catch (\PDOException $e) {
    // DB failure should not prevent a response — log and continue
    error_log("honeypoet: SQLite error: " . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Response
// ---------------------------------------------------------------------------

require_once __DIR__ . '/poet.php';

if ($clean_path === '/' && $method === 'GET') {
    // Gallery page for human visitors
    serve_gallery();
} elseif ($clean_path === '/museum' && $method === 'GET') {
    // Museum stats page for human visitors
    serve_museum();
} else {
    // Generate creative response from template banks
    $response = poet_respond($attack_category, [
        'path'                => $clean_path,
        'method'              => $method,
        'query_string'        => $query_string,
        'user_agent'          => $user_agent,
        'body'                => $body,
        'credential_username' => $credential_username,
        'credential_password' => $credential_password,
    ]);

    // Update the DB row with the response
    if (isset($db, $visit_id) && $visit_id) {
        try {
            $update = $db->prepare('UPDATE visits SET response_type = :type, response_content = :content, llm_generated = 0 WHERE id = :id');
            $update->execute([
                ':type'    => $response['type'],
                ':content' => $response['content'],
                ':id'      => $visit_id,
            ]);
        } catch (\PDOException $e) {
            error_log("honeypoet: response update error: " . $e->getMessage());
        }
    }

    // Serve to the bot
    http_response_code(200);
    header('Content-Type: ' . $response['content_type']);
    header('X-Content-Type-Options: nosniff');
    echo $response['content'];
}
exit;

// ===========================================================================
// Gallery page — "The world reveals itself"
// ===========================================================================

function serve_gallery(): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');

    echo <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>honeypoet — the world reveals itself</title>
    <meta name="description" content="Every knock on the door gets a poem. The Honeypo(e)t listens to the background radiation of the internet — the port scans, credential stuffing, and .env probes — and turns it into verse. Security research as art. Art as public education.">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 100%; height: 100%; background: #fff1e5; overflow: hidden; }
        #layout {
            display: flex; flex-direction: column; align-items: center;
            width: 100%; height: 100%;
        }
        #header {
            text-align: center; pointer-events: none;
            padding: 12px 0 0; flex-shrink: 0;
        }
        #header h1 {
            font-family: Georgia, serif; font-size: 20px; font-weight: normal;
            color: #000; letter-spacing: 0.04em;
        }
        #header .tagline {
            font-family: Georgia, serif; font-size: 12px; font-style: italic;
            color: rgba(0,0,0,0.5); margin-top: 2px;
        }
        #gap { flex-shrink: 0; }
        canvas { display: block; flex-shrink: 0; }
        #map-wrap { position: relative; flex-shrink: 0; }
        #footer {
            text-align: center; pointer-events: none; flex-shrink: 0;
            font-family: 'Courier New', monospace; font-size: 11px;
            line-height: 1.6; color: #000;
        }
        #counter { letter-spacing: 0.03em; }
        #utc-note { font-size: 9px; color: rgba(0,0,0,0.4); margin-top: 1px; }
        #credits { font-size: 9px; color: rgba(0,0,0,0.35); margin-top: 4px; pointer-events: auto; }
        #credits a { color: rgba(0,0,0,0.45); text-decoration: none; }
        #credits a:hover { color: rgba(0,0,0,0.7); }
        #poem-card {
            position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%);
            max-width: 420px; width: 80%;
            background: rgba(242,229,217,0.93); backdrop-filter: blur(2px);
            border-radius: 6px; padding: 10px 16px 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            font-family: Georgia, serif; font-size: 14px; line-height: 1.6;
            color: #222; opacity: 0; transition: opacity 0.6s ease;
            white-space: pre-line; pointer-events: auto;
        }
        #poem-card.visible { opacity: 1; }
        #poem-card .attrib {
            font-size: 11px; font-style: italic; color: rgba(0,0,0,0.45);
            margin-bottom: 6px;
        }
        #poem-card .verse { pointer-events: none; }
        #poem-nav {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 8px; font-family: 'Courier New', monospace; font-size: 11px;
        }
        #poem-nav button {
            background: none; border: 1px solid rgba(0,0,0,0.2); border-radius: 3px;
            padding: 2px 10px; font-family: 'Courier New', monospace; font-size: 11px;
            color: #222; cursor: pointer;
        }
        #poem-nav button:hover { background: rgba(0,0,0,0.05); }
        #poem-nav button:disabled { opacity: 0.3; cursor: default; }
        #poem-nav .pos { color: rgba(0,0,0,0.4); }
        #tooltip {
            position: absolute; pointer-events: none;
            background: rgba(0,0,0,0.8); color: #fff;
            padding: 4px 8px; border-radius: 4px;
            font-size: 11px; font-family: 'Courier New', monospace;
            display: none; z-index: 10; white-space: nowrap;
        }
        #filter-label {
            font-family: 'Courier New', monospace; font-size: 11px;
            color: rgba(0,0,0,0.5); margin-bottom: 6px;
        }
        #filter-label a { color: rgba(0,0,0,0.5); text-decoration: none; cursor: pointer; }
        #filter-label a:hover { color: rgba(0,0,0,0.7); }
        #poem-hide {
            position: absolute; top: 6px; right: 8px;
            background: none; border: none; font-size: 14px;
            color: rgba(0,0,0,0.3); cursor: pointer;
            font-family: 'Courier New', monospace; line-height: 1; padding: 2px 4px;
        }
        #poem-hide:hover { color: rgba(0,0,0,0.6); }
        #dot-hint {
            position: absolute; bottom: 16px; left: 16px;
            font-family: 'Courier New', monospace; font-size: 11px;
            color: rgba(0,0,0,0.35); pointer-events: none;
            transition: opacity 0.8s ease; z-index: 5;
        }
        #dot-hint.hidden { opacity: 0; }
        .meta {
            font-family: 'Courier New', monospace; font-size: 10px;
            color: rgba(0,0,0,0.35); margin-top: 2px; margin-bottom: 4px;
        }
        #counter a { color: #000; text-decoration: underline; pointer-events: auto; cursor: pointer; }
        #counter a:hover { color: rgba(0,0,0,0.6); }

        /* --- Mobile / small screens --- */
        @media (max-width: 600px) {
            html, body { overflow-y: auto; }
            #layout { height: auto; min-height: 100%; }
            #header { padding: 8px 0 0; }
            #header h1 { font-size: 16px; }
            #header .tagline { font-size: 10px; }
            #map-wrap { width: 100% !important; }
            canvas { width: 100% !important; }
            #poem-card {
                position: fixed; bottom: 0; left: 0; right: 0;
                transform: none; width: 100%; max-width: 100%;
                border-radius: 12px 12px 0 0; padding: 10px 14px 12px;
                max-height: 50vh; overflow-y: auto;
                font-size: 13px; line-height: 1.5;
                box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
                z-index: 20;
            }
            #poem-card .verse { font-size: 13px; }
            #poem-nav { font-size: 10px; }
            #poem-nav button { font-size: 10px; padding: 4px 12px; }
            #footer { font-size: 10px; padding: 4px 12px; }
            #counter { font-size: 10px; line-height: 1.5; }
            #dot-hint { font-size: 10px; bottom: 10px; left: 10px; }
            #tooltip { display: none !important; }
        }
    </style>
</head>
<body>
    <div id="manifesto" style="display:none;max-width:36em;margin:10vh auto;padding:2em;font-family:Georgia,serif;line-height:1.7;background:#fff;color:#222">
        <p style="font-style:italic;font-size:1.2em;margin-bottom:2em">&ldquo;Every knock on the door gets a poem.&rdquo;</p>
        <p>There is a quiet violence to the internet. Thousands of times a day, machines knock on every door they can find &mdash; probing for unlocked WordPress installs, exposed <code>.env</code> files, forgotten admin panels, databases left ajar. It is relentless, mechanical, and invisible. Most people have no idea it&rsquo;s happening.</p>
        <p>The Honeypo(e)t listens.</p>
        <p>It sits in the open, looking like a server with something to hide. The scanners come &mdash; they always do &mdash; and instead of silence or a slammed door, they find verse. A haiku for the WordPress hunter. A confession for the credential thief. A meditation on doors and keys for the brute-forcer.</p>
        <p>The bots won&rsquo;t read any of it. They never do. They check the status code and move on.</p>
        <p>But <em>you</em> can read it. That&rsquo;s what the gallery is for.</p>
    </div>
    <div id="layout">
        <div id="header">
            <h1>The Honeypo(e)t</h1>
            <div class="tagline">&ldquo;Every knock on the door gets a poem.&rdquo;</div>
        </div>
        <div id="gap"></div>
        <div id="map-wrap">
            <canvas id="map"></canvas>
            <div id="tooltip"></div>
            <div id="dot-hint"></div>
            <div id="poem-card" role="dialog" aria-label="Poem"></div>
        </div>
        <div id="gap2"></div>
        <div id="footer">
            <div id="counter"></div>
            <div id="utc-note">Universal Time (UTC)</div>
            <div id="credits"><a href="/museum">The Museum</a> &middot; &copy; 2026 M. Quest &middot; <a href="https://github.com/vrontier/honeypoet">https://github.com/vrontier/honeypoet</a></div>
        </div>
    </div>
    <script>
    (function() {
        var canvas = document.getElementById('map');
        var ctx = canvas.getContext('2d');
        var counter = document.getElementById('counter');
        var gap1 = document.getElementById('gap');
        var gap2 = document.getElementById('gap2');
        var mapWrap = document.getElementById('map-wrap');
        var poemCard = document.getElementById('poem-card');
        var tooltip = document.getElementById('tooltip');
        var dpr = window.devicePixelRatio || 1;
        var locations = [];
        var total = 0;
        var hoverLoc = null;
        var zoom = 1, panLng = 0, panLat = 0;
        var dragging = false, dragMoved = false;
        var dragStartX = 0, dragStartY = 0, dragStartLng = 0, dragStartLat = 0;

        function projX(lng) { return ((lng - panLng) * zoom + 180) / 360 * canvas.width; }
        function projY(lat) { return ((panLat - lat) * zoom + 90) / 180 * canvas.height; }
        function unprojLng(px) { return (px / canvas.width * 360 - 180) / zoom + panLng; }
        function unprojLat(py) { return panLat - (py / canvas.height * 180 - 90) / zoom; }
        function clampPan() {
            var maxLng = Math.max(0, 180 - 180 / zoom);
            var maxLat = Math.max(0, 90 - 90 / zoom);
            panLng = Math.max(-maxLng, Math.min(maxLng, panLng));
            panLat = Math.max(-maxLat, Math.min(maxLat, panLat));
        }

        // Continent outlines — Natural Earth 110m land polygons (public domain)
        // Flat array of [lng, lat, lng, lat, ...] with null separators between polygons
        var W = [-60,-80,-60,-81,-62,-81,-64,-81,-66,-81,-66,-80,-64,-80,-62,-80,-61,-80,-60,-80,null,-159,-79,-161,-80,-162,-79,-163,-79,-164,-79,-163,-78,-161,-78,-160,-79,-159,-79,null,-45,-78,-44,-78,-43,-79,-43,-80,-45,-80,-47,-81,-48,-81,-50,-81,-53,-81,-54,-81,-54,-80,-52,-80,-51,-80,-50,-79,-49,-78,-48,-78,-47,-78,-45,-78,null,-121,-74,-120,-74,-119,-73,-119,-74,-120,-74,-122,-74,-123,-74,-122,-73,-121,-74,null,-126,-73,-124,-74,-125,-74,-126,-74,-127,-73,-126,-73,null,-99,-72,-98,-72,-97,-72,-96,-73,-97,-72,-98,-72,-99,-72,-101,-73,-102,-72,-100,-72,-99,-72,null,-68,-71,-69,-72,-70,-72,-71,-73,-72,-72,-73,-72,-74,-72,-75,-72,-74,-71,-73,-71,-72,-71,-72,-70,-71,-69,-70,-69,-69,-70,-69,-71,-68,-71,null,-59,-64,-60,-64,-61,-64,-61,-65,-62,-65,-63,-65,-63,-66,-62,-66,-63,-66,-64,-67,-65,-67,-66,-68,-65,-68,-65,-69,-64,-69,-63,-69,-63,-70,-62,-70,-62,-71,-61,-72,-61,-73,-61,-74,-62,-74,-63,-75,-64,-75,-66,-76,-67,-76,-68,-76,-70,-76,-71,-77,-72,-77,-74,-77,-76,-77,-77,-77,-75,-77,-74,-78,-75,-78,-76,-78,-78,-78,-78,-79,-77,-80,-75,-80,-73,-80,-71,-81,-70,-81,-68,-81,-66,-81,-63,-82,-62,-82,-60,-82,-59,-83,-58,-83,-57,-83,-55,-83,-54,-82,-52,-82,-50,-82,-47,-82,-45,-82,-43,-82,-42,-82,-41,-81,-38,-81,-36,-81,-34,-81,-32,-81,-30,-81,-29,-80,-30,-80,-30,-79,-32,-79,-34,-79,-36,-79,-36,-78,-35,-78,-34,-78,-32,-78,-31,-77,-30,-77,-29,-77,-28,-76,-26,-76,-25,-76,-24,-76,-22,-76,-21,-76,-20,-76,-19,-75,-18,-75,-17,-75,-16,-74,-15,-74,-16,-74,-16,-73,-15,-73,-14,-73,-13,-73,-12,-72,-11,-72,-10,-71,-9,-71,-9,-72,-7,-72,-7,-71,-6,-71,-4,-71,-3,-71,-2,-71,-1,-71,0,-72,1,-71,2,-71,3,-71,4,-71,5,-71,6,-70,7,-70,8,-70,10,-70,11,-71,12,-71,12,-70,13,-70,15,-70,16,-70,17,-70,18,-70,19,-70,20,-70,21,-70,22,-70,23,-71,24,-71,25,-70,26,-70,27,-70,28,-70,29,-70,30,-70,31,-70,32,-70,33,-69,34,-69,35,-69,36,-69,37,-69,38,-70,39,-70,40,-70,40,-69,41,-69,42,-69,43,-68,44,-68,45,-68,46,-68,47,-68,48,-67,49,-67,50,-67,51,-67,52,-66,53,-66,54,-66,55,-66,56,-66,57,-66,57,-67,58,-67,59,-67,60,-67,61,-68,62,-68,63,-68,64,-67,65,-68,66,-68,67,-68,68,-68,69,-68,70,-69,70,-70,69,-70,68,-70,68,-71,69,-71,68,-71,68,-72,69,-72,70,-72,71,-72,72,-72,72,-71,73,-71,73,-70,74,-70,76,-70,77,-70,78,-69,79,-68,80,-68,81,-68,82,-67,83,-67,84,-67,85,-67,86,-67,87,-67,88,-66,89,-67,90,-67,91,-67,92,-67,93,-67,94,-67,95,-67,96,-67,97,-67,98,-67,99,-67,100,-67,101,-67,102,-66,103,-66,104,-66,105,-66,106,-67,107,-67,108,-67,109,-67,110,-67,111,-66,112,-66,113,-66,114,-66,115,-66,116,-67,117,-67,119,-67,120,-67,121,-67,122,-67,123,-66,124,-67,125,-67,126,-67,127,-67,128,-67,129,-67,130,-67,131,-66,132,-66,133,-66,134,-66,135,-66,135,-65,136,-66,137,-67,139,-67,140,-67,141,-67,142,-67,143,-67,144,-67,145,-67,146,-67,146,-68,147,-68,148,-68,149,-68,150,-69,151,-69,153,-69,154,-69,155,-69,156,-69,157,-69,158,-69,159,-70,160,-70,161,-70,162,-71,163,-71,164,-71,165,-71,166,-71,167,-71,168,-71,169,-71,171,-71,171,-72,170,-73,169,-74,168,-74,167,-74,166,-74,166,-75,165,-75,164,-75,164,-76,163,-77,164,-77,164,-78,165,-78,167,-78,167,-79,165,-79,164,-79,162,-79,161,-80,160,-81,161,-81,162,-82,164,-82,165,-83,167,-83,169,-83,169,-84,172,-84,173,-84,176,-84,178,-84,180,-85,180,-90,-180,-90,-180,-85,-179,-84,-177,-84,-176,-84,-174,-85,-173,-84,-170,-84,-169,-84,-167,-85,-164,-85,-162,-85,-158,-85,-155,-85,-151,-85,-149,-86,-146,-85,-143,-85,-147,-85,-150,-84,-151,-84,-154,-84,-153,-83,-153,-82,-155,-82,-155,-81,-157,-81,-154,-81,-152,-81,-151,-81,-149,-81,-147,-81,-146,-80,-147,-80,-148,-80,-150,-79,-152,-79,-153,-79,-155,-79,-156,-79,-157,-78,-158,-78,-158,-77,-157,-77,-155,-77,-154,-77,-153,-77,-151,-77,-150,-77,-149,-77,-148,-77,-146,-76,-146,-75,-145,-75,-144,-76,-143,-75,-142,-75,-140,-75,-139,-75,-138,-75,-136,-75,-135,-74,-134,-74,-132,-74,-131,-74,-130,-74,-128,-74,-127,-74,-125,-75,-124,-74,-123,-74,-121,-75,-120,-74,-119,-74,-117,-74,-116,-74,-115,-74,-114,-74,-113,-74,-112,-75,-111,-74,-110,-75,-109,-75,-108,-75,-106,-75,-105,-75,-103,-75,-102,-75,-101,-75,-100,-75,-101,-75,-101,-74,-103,-74,-103,-73,-104,-73,-103,-73,-102,-73,-100,-73,-99,-73,-98,-73,-98,-74,-96,-74,-95,-73,-94,-73,-92,-73,-91,-73,-90,-73,-89,-73,-88,-73,-87,-73,-86,-73,-85,-73,-84,-74,-83,-74,-81,-74,-81,-73,-80,-73,-79,-74,-78,-73,-77,-74,-76,-74,-75,-74,-74,-74,-73,-73,-72,-73,-70,-73,-69,-73,-68,-73,-67,-72,-68,-71,-68,-70,-69,-70,-68,-69,-67,-68,-68,-68,-68,-67,-67,-67,-66,-66,-65,-66,-64,-65,-63,-65,-62,-65,-61,-64,-60,-64,-59,-64,-59,-63,-58,-63,-57,-64,-58,-64,-59,-64,null,-68,-54,-66,-54,-65,-55,-66,-55,-67,-55,-68,-56,-69,-55,-70,-55,-71,-55,-72,-54,-73,-54,-75,-53,-74,-53,-72,-54,-71,-54,-70,-53,-69,-53,-68,-53,-68,-54,null,-59,-51,-58,-52,-59,-52,-60,-52,-61,-52,-60,-51,-59,-52,-59,-51,null,70,-50,69,-50,69,-49,70,-49,71,-49,70,-50,null,145,-41,146,-41,147,-41,148,-41,148,-42,148,-43,147,-44,146,-44,145,-43,145,-42,145,-41,null,173,-41,174,-41,174,-42,173,-43,173,-44,172,-44,171,-44,171,-45,171,-46,170,-46,169,-47,168,-47,168,-46,167,-46,167,-45,168,-44,169,-44,170,-44,171,-43,172,-42,172,-41,173,-40,173,-41,null,175,-36,175,-37,176,-37,176,-38,177,-38,178,-38,179,-38,178,-39,177,-39,177,-40,177,-41,176,-41,175,-42,175,-41,175,-40,174,-40,174,-39,175,-39,175,-38,175,-37,174,-37,174,-36,173,-35,173,-34,174,-35,175,-36,null,167,-22,166,-22,165,-22,165,-21,164,-20,165,-20,165,-21,166,-21,167,-22,null,178,-17,179,-18,178,-18,177,-18,178,-17,178,-18,178,-17,null,179,-17,179,-16,180,-16,180,-17,179,-17,null,168,-16,168,-17,167,-16,168,-16,null,50,-14,50,-15,50,-16,50,-15,50,-16,50,-17,49,-17,49,-18,49,-19,49,-20,48,-22,48,-24,47,-25,46,-25,45,-26,45,-25,44,-25,44,-24,43,-23,43,-22,43,-21,44,-21,44,-20,44,-19,44,-18,44,-17,44,-16,45,-16,46,-16,47,-15,48,-15,48,-14,49,-13,49,-12,50,-12,50,-13,50,-14,null,144,-14,144,-15,145,-14,145,-15,145,-16,146,-17,146,-18,146,-19,147,-19,148,-20,149,-20,149,-21,150,-22,150,-23,151,-22,151,-23,152,-24,153,-25,153,-26,153,-27,154,-28,154,-29,153,-29,153,-30,153,-31,153,-32,152,-33,151,-34,151,-35,150,-36,150,-37,149,-38,148,-38,147,-38,147,-39,146,-39,145,-39,145,-38,144,-38,144,-39,143,-39,142,-38,141,-38,140,-37,140,-36,139,-36,138,-36,138,-35,138,-34,138,-35,137,-35,138,-34,138,-33,137,-34,136,-34,136,-35,135,-34,135,-33,134,-33,133,-32,132,-32,131,-31,130,-32,128,-32,127,-32,126,-32,125,-33,124,-33,124,-34,123,-34,122,-34,121,-34,120,-34,119,-35,119,-34,119,-35,118,-35,117,-35,116,-34,115,-34,116,-33,116,-32,115,-31,115,-30,115,-29,114,-28,114,-27,113,-27,113,-26,114,-27,113,-26,114,-26,114,-25,113,-24,114,-24,114,-23,114,-22,114,-23,115,-22,115,-21,116,-21,117,-21,118,-20,119,-20,120,-20,121,-20,121,-19,122,-19,122,-18,122,-17,123,-16,123,-17,124,-17,124,-16,125,-15,126,-15,126,-14,127,-14,128,-14,128,-15,129,-15,130,-15,129,-14,130,-14,130,-13,131,-13,131,-12,132,-12,133,-12,132,-11,133,-11,134,-12,135,-12,136,-12,137,-12,137,-13,136,-13,136,-14,135,-15,136,-15,136,-16,137,-16,138,-16,138,-17,139,-17,140,-18,141,-17,141,-16,142,-15,142,-14,142,-13,142,-12,142,-11,143,-11,143,-12,144,-13,144,-14,null,162,-10,162,-11,161,-10,162,-10,null,121,-10,120,-10,119,-10,120,-9,120,-10,121,-10,null,161,-10,160,-10,160,-9,161,-10,null,162,-10,161,-9,161,-8,161,-9,162,-10,null,124,-10,123,-10,124,-10,124,-9,125,-9,126,-8,127,-8,127,-9,126,-9,125,-9,124,-10,null,118,-8,119,-8,119,-9,118,-9,117,-9,117,-8,118,-8,null,123,-8,123,-9,121,-9,120,-9,120,-8,121,-8,121,-9,122,-8,123,-8,null,160,-8,160,-9,159,-8,158,-7,159,-8,160,-8,null,158,-7,157,-7,156,-7,157,-7,158,-7,null,109,-7,111,-7,111,-6,113,-7,113,-8,114,-8,116,-8,115,-9,113,-8,112,-8,111,-8,109,-8,108,-8,106,-7,105,-7,106,-6,107,-6,108,-6,109,-7,null,135,-6,134,-7,134,-6,134,-5,135,-6,null,156,-7,155,-7,155,-6,155,-5,155,-6,156,-6,156,-7,null,152,-5,151,-6,150,-6,149,-6,148,-6,148,-5,149,-6,150,-6,150,-5,150,-6,151,-5,152,-5,152,-4,152,-5,null,127,-3,127,-4,126,-4,126,-3,127,-3,null,130,-3,131,-4,130,-3,129,-3,128,-3,129,-3,130,-3,null,153,-4,153,-5,153,-4,152,-4,152,-3,151,-3,152,-3,153,-4,null,134,-1,134,-3,135,-3,136,-2,137,-2,138,-2,139,-2,140,-2,141,-3,143,-3,145,-4,146,-5,148,-6,148,-7,147,-7,148,-8,149,-9,149,-10,150,-10,151,-10,151,-11,150,-11,150,-10,149,-10,148,-10,147,-9,146,-8,145,-8,144,-8,143,-8,143,-9,142,-9,141,-9,140,-8,139,-8,138,-8,139,-7,138,-6,138,-5,136,-5,135,-4,134,-4,133,-4,133,-3,132,-3,133,-2,134,-2,132,-2,131,-1,132,-1,132,0,134,-1,null,125,1,124,0,123,0,121,0,120,0,120,-1,121,-1,123,-1,122,-2,122,-3,122,-4,123,-5,123,-6,122,-5,123,-4,122,-5,121,-5,122,-4,121,-4,121,-3,120,-3,120,-4,120,-6,119,-5,120,-4,119,-3,119,-2,119,-1,120,0,120,1,121,1,122,1,123,1,124,1,125,2,125,1,null,129,1,129,0,128,0,128,-1,128,0,127,1,128,2,129,2,129,1,null,106,-6,105,-6,104,-5,103,-4,102,-4,101,-3,101,-2,100,-1,99,0,99,1,99,2,98,2,97,3,96,4,95,5,96,5,97,5,98,4,99,4,100,3,101,2,102,2,102,1,103,1,104,0,103,-1,104,-1,105,-2,106,-2,106,-3,106,-4,106,-6,null,118,2,119,1,118,1,117,0,118,-1,117,-1,117,-2,116,-4,115,-4,114,-3,113,-3,112,-3,111,-3,110,-3,110,-2,110,-1,109,0,109,1,110,2,111,2,111,3,112,3,113,3,114,4,114,5,115,5,116,6,117,7,118,6,119,5,118,5,119,4,118,4,117,3,118,2,null,126,8,127,7,126,6,126,7,125,7,126,6,125,6,124,6,124,7,124,8,123,7,122,7,122,8,123,8,123,9,124,8,125,9,125,10,126,9,126,8,null,81,6,80,6,80,7,80,8,80,10,81,9,82,8,82,6,81,6,null,-61,10,-62,10,-62,11,-61,11,-61,10,null,124,10,123,9,122,10,123,10,123,11,123,10,124,11,124,10,null,119,9,117,8,118,9,118,10,119,10,120,11,119,10,119,9,null,122,12,123,12,123,11,122,10,122,11,122,12,null,126,12,126,11,125,11,125,10,125,11,124,11,125,11,125,12,124,13,125,13,126,12,null,122,13,121,12,121,13,120,13,121,13,122,13,null,121,19,122,18,123,17,122,16,122,15,122,14,123,14,124,14,124,13,123,13,123,14,123,13,122,14,121,14,121,15,121,14,120,15,120,16,120,18,121,19,null,-66,18,-67,18,-67,19,-66,19,-66,18,null,-77,18,-78,18,-78,19,-78,18,-77,18,-76,18,-77,18,null,-73,20,-72,20,-71,20,-70,20,-70,19,-69,19,-68,19,-69,18,-70,18,-71,18,-72,18,-73,18,-74,18,-74,19,-73,19,-73,18,-72,19,-73,19,-73,20,null,110,19,109,18,109,19,109,20,110,20,111,20,111,19,110,19,null,-156,19,-156,20,-155,20,-155,19,-156,19,null,-80,23,-79,22,-78,23,-78,22,-77,22,-77,21,-76,21,-75,21,-74,20,-75,20,-76,20,-78,20,-77,20,-77,21,-78,21,-79,22,-80,22,-81,22,-82,22,-82,23,-83,23,-83,22,-84,22,-85,22,-84,22,-84,23,-83,23,-82,23,-81,23,-80,23,null,121,23,121,22,120,23,120,24,121,25,122,25,122,24,121,23,null,-78,27,-79,26,-79,27,-78,27,null,-77,27,-77,26,-77,27,-78,27,-77,27,null,135,34,134,33,134,34,133,33,132,33,133,34,134,34,135,34,null,35,36,34,35,33,35,32,35,33,35,34,35,35,36,null,24,36,24,35,25,35,26,35,25,35,24,35,24,36,null,16,38,15,37,14,37,12,38,13,38,14,38,15,38,16,38,null,9,41,10,41,10,39,9,39,8,39,8,40,8,41,9,41,null,141,37,141,36,140,35,139,35,137,35,136,33,135,34,135,35,133,34,132,34,131,34,132,33,131,31,130,31,130,32,130,33,129,33,130,34,131,34,132,35,133,35,135,36,136,36,137,37,139,38,140,39,140,41,141,41,142,40,142,39,141,38,141,37,null,10,42,9,41,9,42,9,43,10,42,null,144,44,145,44,146,43,144,43,143,42,142,43,141,42,140,42,140,43,141,43,142,45,142,46,143,45,144,44,null,-64,47,-63,46,-62,46,-63,46,-64,46,-64,47,null,-62,49,-64,49,-65,50,-64,50,-63,50,-62,49,null,-124,49,-124,48,-126,49,-127,50,-128,50,-128,51,-127,51,-127,50,-126,50,-125,50,-125,49,-124,49,null,-56,51,-57,50,-56,50,-55,50,-56,50,-55,49,-54,50,-53,49,-54,49,-53,49,-53,48,-53,47,-54,47,-54,48,-55,47,-56,47,-55,47,-56,48,-57,48,-59,48,-59,49,-58,49,-57,51,-56,52,-55,52,-56,51,null,-133,54,-132,54,-132,53,-131,52,-132,52,-132,53,-133,53,-133,54,null,144,51,145,49,143,49,143,48,144,47,144,46,143,47,142,46,142,47,142,48,142,49,142,50,142,51,142,52,142,53,143,54,142,54,143,54,143,53,143,52,144,51,null,-7,52,-9,52,-10,52,-9,53,-10,54,-8,55,-7,55,-6,55,-6,54,-6,53,-7,52,null,13,56,12,55,11,55,11,56,12,56,13,56,null,-153,57,-154,57,-155,57,-154,58,-153,58,-152,58,-153,57,null,-3,59,-4,58,-3,58,-2,58,-2,57,-3,56,-2,56,-1,55,0,54,0,53,2,53,2,52,1,52,1,51,-1,51,-2,51,-3,51,-4,50,-5,50,-6,50,-4,51,-3,51,-5,52,-4,52,-5,53,-3,53,-3,54,-4,55,-5,55,-5,56,-6,55,-6,56,-6,57,-6,58,-5,59,-4,59,-3,59,null,-82,63,-83,62,-84,62,-83,63,-82,63,null,-172,64,-171,64,-170,64,-170,63,-169,63,-170,63,-171,63,-172,63,-172,64,null,-85,66,-85,65,-84,65,-83,65,-82,64,-81,64,-80,64,-81,63,-83,64,-84,64,-86,63,-86,64,-87,64,-86,64,-86,65,-86,66,-85,66,null,-15,66,-14,65,-15,64,-18,64,-19,63,-20,64,-23,64,-22,64,-24,65,-22,65,-24,66,-22,66,-21,66,-19,66,-18,66,-16,67,-15,66,null,-76,67,-77,67,-77,68,-76,68,-75,68,-75,67,-76,67,null,-175,67,-174,66,-175,67,-172,67,-170,66,-171,66,-173,65,-173,64,-174,64,-175,65,-176,65,-177,66,-178,65,-179,66,-180,66,-179,65,-180,65,-180,69,-178,68,-175,67,null,-96,69,-98,69,-100,69,-99,70,-98,70,-97,70,-96,69,null,180,71,179,71,180,72,180,71,null,-179,71,-180,71,-180,72,-179,72,-178,71,-179,71,null,-91,69,-91,68,-89,69,-88,69,-88,68,-87,67,-86,68,-86,69,-86,70,-84,70,-83,70,-81,69,-82,68,-81,68,-81,67,-83,66,-85,66,-86,67,-86,66,-87,65,-88,64,-90,64,-91,64,-91,63,-92,63,-93,62,-94,61,-95,60,-95,59,-93,59,-93,58,-92,57,-91,57,-89,57,-88,56,-87,56,-86,56,-85,55,-83,55,-82,55,-82,54,-82,53,-81,52,-80,51,-79,52,-79,53,-79,54,-80,55,-78,55,-77,56,-77,57,-77,58,-79,59,-77,60,-78,61,-78,62,-77,63,-76,62,-75,62,-74,62,-73,62,-72,62,-71,61,-70,61,-70,60,-69,59,-68,59,-68,58,-66,59,-65,60,-64,59,-63,58,-61,57,-62,56,-60,56,-60,55,-58,55,-57,55,-57,54,-56,54,-56,53,-56,52,-57,51,-59,51,-60,50,-62,50,-64,50,-65,50,-66,50,-67,50,-69,49,-70,48,-71,47,-70,47,-69,48,-67,49,-65,49,-64,49,-65,48,-65,47,-64,46,-63,46,-62,46,-61,47,-60,46,-61,45,-63,45,-64,44,-65,44,-66,44,-64,45,-66,45,-67,45,-68,44,-69,44,-70,44,-71,43,-71,42,-70,42,-71,41,-72,41,-73,41,-74,41,-72,41,-73,41,-74,41,-74,40,-75,39,-76,39,-75,39,-75,38,-76,37,-76,38,-76,39,-77,39,-76,38,-77,38,-76,38,-76,37,-76,36,-76,35,-77,35,-78,34,-79,34,-79,33,-80,33,-81,32,-81,31,-81,30,-81,29,-81,28,-80,27,-80,26,-80,25,-81,25,-81,26,-82,26,-82,27,-83,27,-83,28,-83,29,-84,30,-85,30,-86,30,-88,30,-89,30,-90,30,-89,30,-89,29,-90,29,-91,29,-92,30,-93,30,-94,30,-95,29,-96,29,-97,28,-97,27,-97,26,-98,25,-98,24,-98,23,-98,22,-97,21,-97,20,-96,19,-95,19,-94,18,-93,19,-92,19,-91,19,-91,20,-90,21,-89,21,-88,21,-87,22,-87,21,-87,20,-88,20,-87,19,-88,19,-88,18,-88,19,-88,18,-88,17,-89,16,-88,16,-87,16,-86,16,-85,16,-84,16,-84,15,-83,15,-83,14,-84,14,-84,13,-83,13,-83,12,-84,12,-84,11,-83,10,-82,9,-81,9,-80,9,-80,10,-79,10,-79,9,-78,9,-77,9,-76,9,-76,10,-75,11,-74,11,-73,11,-73,12,-72,12,-71,12,-72,11,-72,10,-72,9,-71,9,-71,10,-71,11,-70,11,-70,12,-70,11,-69,11,-68,11,-67,11,-66,11,-66,10,-65,10,-64,10,-64,11,-63,11,-62,11,-63,10,-62,10,-61,9,-60,9,-60,8,-59,8,-58,7,-58,6,-57,6,-56,6,-55,6,-54,6,-53,5,-52,5,-52,4,-51,4,-51,2,-50,2,-50,1,-51,0,-50,0,-49,0,-49,-1,-48,-1,-47,-1,-45,-2,-44,-2,-45,-3,-43,-2,-41,-3,-40,-3,-39,-4,-37,-5,-36,-5,-35,-5,-35,-7,-35,-9,-36,-10,-37,-11,-38,-12,-38,-13,-39,-13,-39,-14,-39,-16,-39,-17,-39,-18,-40,-18,-40,-20,-41,-21,-41,-22,-42,-22,-42,-23,-43,-23,-45,-23,-45,-24,-46,-24,-48,-25,-48,-26,-49,-27,-48,-27,-49,-28,-49,-29,-50,-29,-51,-31,-52,-32,-53,-33,-53,-34,-54,-34,-55,-35,-56,-35,-57,-34,-58,-34,-57,-35,-57,-36,-57,-37,-58,-38,-59,-39,-61,-39,-62,-39,-62,-40,-62,-41,-63,-41,-64,-41,-65,-41,-65,-42,-64,-42,-63,-43,-64,-43,-65,-43,-65,-45,-66,-45,-67,-45,-67,-46,-68,-46,-67,-47,-66,-47,-66,-48,-67,-49,-68,-50,-69,-50,-69,-51,-69,-52,-68,-52,-69,-52,-70,-53,-71,-53,-71,-54,-73,-54,-74,-53,-75,-52,-75,-51,-75,-50,-76,-49,-75,-48,-74,-47,-76,-47,-75,-46,-74,-44,-73,-44,-73,-42,-74,-43,-74,-42,-74,-40,-73,-39,-74,-38,-74,-37,-73,-37,-73,-36,-72,-34,-71,-32,-72,-31,-71,-30,-71,-29,-71,-28,-71,-26,-70,-24,-70,-21,-70,-20,-70,-18,-71,-18,-71,-17,-73,-16,-75,-15,-76,-15,-76,-14,-77,-12,-78,-10,-79,-8,-80,-7,-81,-7,-81,-6,-81,-5,-81,-4,-80,-3,-80,-2,-80,-3,-81,-2,-81,-1,-80,0,-80,1,-79,1,-79,2,-78,3,-77,4,-77,5,-78,6,-77,6,-77,7,-78,7,-78,8,-79,9,-80,9,-80,8,-80,7,-81,7,-81,8,-82,8,-83,8,-84,8,-84,9,-85,10,-86,10,-86,11,-87,12,-88,13,-87,13,-88,13,-89,13,-90,14,-91,14,-92,14,-92,15,-93,16,-94,16,-95,16,-96,16,-97,16,-98,16,-99,17,-100,17,-101,17,-102,18,-104,18,-104,19,-105,19,-105,20,-106,20,-105,21,-106,21,-105,21,-106,22,-106,23,-107,24,-108,25,-109,26,-110,27,-111,28,-112,28,-112,29,-113,30,-113,31,-114,32,-115,32,-115,31,-115,30,-114,30,-114,29,-113,29,-113,28,-112,28,-112,27,-111,26,-111,25,-111,24,-110,24,-109,23,-110,23,-111,24,-112,24,-112,25,-112,26,-113,26,-113,27,-114,27,-115,28,-114,28,-114,29,-115,29,-116,30,-116,31,-117,32,-117,33,-118,34,-119,34,-120,34,-121,35,-122,36,-123,38,-124,39,-124,40,-124,41,-124,42,-125,43,-124,44,-124,46,-124,47,-124,48,-125,48,-123,48,-123,47,-122,47,-122,48,-123,49,-125,50,-126,50,-127,51,-128,52,-129,53,-129,54,-131,54,-131,55,-132,55,-132,56,-134,57,-134,58,-135,58,-137,58,-138,59,-140,60,-141,60,-143,60,-144,60,-146,60,-147,61,-148,61,-148,60,-149,60,-150,60,-151,59,-152,59,-152,60,-151,61,-150,61,-151,61,-152,61,-153,60,-154,59,-153,59,-154,58,-155,58,-156,57,-157,57,-158,56,-160,56,-161,55,-162,55,-163,55,-165,54,-165,55,-164,55,-163,55,-162,56,-161,56,-160,56,-159,57,-158,57,-158,58,-157,59,-158,59,-159,59,-159,58,-160,59,-161,59,-162,59,-162,60,-163,60,-164,60,-165,60,-165,61,-166,62,-165,63,-164,63,-163,63,-162,64,-162,63,-161,64,-162,64,-161,65,-162,65,-163,64,-164,65,-165,64,-166,65,-167,65,-168,66,-167,66,-164,67,-164,66,-162,66,-162,67,-164,67,-164,68,-165,68,-167,68,-166,69,-164,69,-163,69,-163,70,-162,70,-161,70,-159,71,-158,71,-157,71,-155,71,-154,71,-152,71,-151,70,-150,71,-148,70,-146,70,-145,70,-144,70,-142,70,-141,70,-139,69,-138,69,-137,69,-136,69,-134,70,-133,70,-131,70,-130,70,-129,70,-128,70,-127,70,-126,69,-124,70,-124,69,-123,70,-121,70,-120,69,-118,69,-116,69,-115,69,-114,68,-115,68,-113,68,-111,68,-110,68,-109,67,-108,68,-109,68,-108,69,-107,69,-106,69,-105,69,-104,68,-103,68,-101,68,-100,68,-98,68,-99,68,-98,69,-96,68,-96,67,-95,68,-94,69,-95,70,-96,70,-96,71,-95,72,-94,72,-93,71,-92,70,-91,69,null,-114,73,-115,73,-112,73,-111,72,-110,73,-109,73,-108,72,-108,73,-107,73,-105,73,-105,72,-104,71,-103,70,-101,70,-103,70,-102,69,-104,69,-106,69,-107,69,-109,69,-112,69,-113,69,-114,69,-115,69,-116,69,-117,70,-115,70,-114,70,-112,70,-114,71,-116,71,-118,71,-116,71,-118,71,-119,72,-118,73,-115,73,-114,73,null,-104,73,-105,73,-107,73,-107,74,-105,74,-104,73,null,-76,73,-77,73,-78,73,-79,73,-80,73,-81,73,-81,74,-80,74,-78,74,-76,73,null,-87,73,-86,73,-85,73,-82,74,-81,73,-81,72,-79,72,-78,73,-76,72,-74,72,-74,71,-72,72,-71,71,-69,71,-68,70,-67,69,-69,69,-66,68,-65,68,-63,67,-62,67,-62,66,-64,65,-65,65,-67,66,-68,66,-67,65,-66,65,-65,64,-65,63,-66,63,-69,64,-67,63,-66,62,-69,62,-71,63,-72,63,-72,64,-73,64,-75,65,-75,64,-78,64,-79,65,-78,65,-76,65,-74,65,-74,66,-73,67,-73,68,-75,69,-77,69,-76,69,-77,70,-78,70,-79,70,-81,70,-85,70,-87,70,-89,70,-90,71,-88,71,-90,71,-90,72,-89,73,-88,74,-86,74,-87,73,null,-100,74,-99,74,-97,74,-97,73,-98,73,-97,73,-97,72,-98,71,-99,71,-100,72,-102,73,-100,73,-102,73,-100,74,null,144,73,142,73,140,73,141,74,142,74,143,73,144,73,null,-93,73,-94,72,-95,72,-96,73,-95,74,-92,74,-91,74,-92,73,-93,73,null,-120,71,-123,71,-124,71,-126,72,-125,73,-124,74,-125,74,-122,74,-120,74,-118,74,-117,74,-116,73,-117,73,-119,73,-120,72,-120,71,null,151,75,150,75,148,75,146,75,148,75,151,75,null,-94,75,-96,75,-97,75,-96,75,-95,76,-94,75,null,145,76,144,75,141,75,139,75,137,75,138,76,139,76,141,76,145,76,null,-98,77,-98,76,-98,75,-100,75,-101,75,-101,76,-103,76,-101,76,-100,77,-99,77,-98,77,null,-108,76,-107,76,-106,76,-106,75,-110,75,-112,74,-114,74,-114,75,-112,75,-116,75,-118,75,-116,76,-115,76,-113,76,-111,76,-109,75,-110,76,-110,77,-109,77,-108,76,null,58,71,57,71,54,71,53,71,52,71,51,72,52,72,52,73,54,74,56,75,58,76,61,76,64,76,66,77,68,77,69,77,68,76,65,76,62,75,58,74,57,73,55,72,56,72,58,71,null,-95,77,-94,77,-92,77,-91,76,-90,76,-89,76,-88,76,-86,75,-85,76,-83,76,-81,76,-80,75,-82,74,-83,75,-86,74,-88,74,-90,75,-92,75,-93,75,-93,76,-94,76,-96,76,-97,77,-95,77,null,-116,78,-116,77,-117,77,-118,76,-120,76,-121,76,-123,76,-121,77,-119,78,-118,77,-116,78,null,107,77,107,76,108,77,111,77,113,76,114,76,114,75,113,75,110,74,109,74,111,74,112,74,113,74,114,73,114,74,116,74,119,74,119,73,123,73,123,74,125,74,127,74,129,73,129,72,128,72,130,71,131,71,132,72,134,71,136,72,137,71,138,72,140,71,139,72,140,73,150,72,153,71,157,71,159,71,160,70,161,69,162,70,164,70,166,69,168,70,170,69,171,69,170,70,174,70,176,70,179,69,180,69,180,65,179,65,177,65,178,64,179,63,179,62,177,63,175,62,174,62,172,61,171,60,170,60,169,61,166,60,165,60,164,60,163,59,162,58,163,58,163,56,162,56,162,55,160,54,160,53,159,53,158,52,157,51,156,52,156,53,155,55,156,57,157,57,157,58,158,58,160,59,162,60,164,61,164,63,163,62,160,61,159,62,157,61,154,60,155,59,153,59,151,59,151,60,150,60,149,59,145,59,142,59,139,57,135,55,137,55,137,54,138,54,139,54,140,54,141,53,141,52,141,51,141,50,140,48,139,47,138,46,137,45,136,44,135,43,134,43,133,43,132,43,131,43,131,42,130,42,130,41,129,41,129,40,128,40,128,39,127,39,128,39,129,37,129,36,129,35,128,35,127,34,126,34,126,35,127,36,126,37,127,37,126,38,125,38,125,39,125,40,124,40,123,40,122,39,121,39,122,39,121,40,122,40,122,41,121,41,120,40,119,39,118,39,118,38,119,38,119,37,120,37,121,38,122,37,123,37,121,37,121,36,120,36,119,35,120,34,121,33,121,32,122,32,122,31,121,31,122,30,122,29,122,28,121,28,120,27,120,26,119,25,117,24,116,23,115,23,114,22,114,23,113,22,112,22,111,21,110,20,110,21,109,22,108,22,107,21,106,20,106,19,106,18,107,17,108,16,109,15,109,13,109,12,108,11,107,10,106,10,105,9,105,10,104,10,103,11,103,12,102,13,101,13,100,13,100,12,99,11,99,10,99,9,100,9,100,8,100,7,101,7,102,7,102,6,103,6,103,5,103,4,103,3,104,3,104,2,104,1,103,2,101,3,101,4,101,5,100,5,100,6,100,7,99,8,98,8,98,9,99,10,98,11,99,11,98,12,99,13,98,14,98,15,98,16,97,17,97,16,95,16,94,16,95,17,94,18,94,19,94,20,93,20,92,21,92,22,91,23,90,23,91,22,90,22,89,22,88,22,87,21,86,20,85,19,84,18,83,18,82,17,82,16,81,16,80,16,80,15,80,14,80,13,80,12,80,10,79,10,79,9,78,9,78,8,77,9,76,10,76,11,75,12,75,13,75,14,74,15,74,16,73,18,73,19,73,20,73,21,71,21,70,21,69,22,70,22,69,23,68,24,67,24,67,25,66,25,65,25,63,25,61,25,60,25,59,26,57,26,57,27,56,27,55,26,53,27,52,28,51,29,50,30,49,30,48,30,48,29,49,28,49,27,50,27,50,26,51,25,51,26,52,26,52,25,51,25,52,24,53,24,54,24,55,25,56,26,56,25,57,24,58,24,59,24,59,23,60,23,60,22,59,22,59,21,58,20,58,19,57,19,57,18,56,18,55,18,55,17,54,17,53,17,52,16,51,15,50,15,49,14,48,14,47,14,47,13,46,13,45,13,44,13,43,13,43,14,43,15,43,16,43,17,42,17,42,18,41,19,40,20,39,21,39,22,39,23,38,24,37,24,37,25,37,26,36,27,35,28,35,29,35,30,35,29,34,28,33,28,32,30,33,29,33,28,34,26,35,25,36,24,35,24,36,23,37,22,37,21,37,20,37,19,38,18,39,17,39,16,40,15,41,14,42,14,42,13,43,13,43,12,43,11,44,11,44,10,45,10,46,11,47,11,48,11,49,11,50,12,51,12,51,11,51,10,51,9,50,8,49,7,49,5,48,4,47,3,46,2,44,1,43,0,42,-1,42,-2,41,-2,40,-3,40,-4,39,-5,39,-6,39,-7,39,-8,40,-9,40,-10,40,-11,40,-12,41,-13,41,-14,41,-15,40,-15,40,-16,39,-17,37,-18,36,-19,35,-20,35,-21,35,-22,36,-22,36,-23,35,-24,36,-24,35,-24,34,-25,33,-25,33,-26,33,-27,32,-28,32,-29,31,-29,31,-30,30,-31,29,-32,28,-33,27,-33,26,-34,25,-34,24,-34,23,-34,22,-34,21,-34,20,-35,19,-34,18,-34,18,-33,18,-32,18,-31,17,-30,16,-29,16,-28,15,-27,15,-26,15,-25,14,-24,14,-23,14,-22,13,-21,13,-20,13,-19,12,-18,12,-17,12,-16,12,-15,12,-14,13,-14,13,-13,13,-12,14,-12,14,-11,13,-10,13,-9,13,-8,13,-7,12,-6,12,-5,11,-4,10,-3,9,-2,9,-1,9,0,9,1,10,2,10,3,9,4,8,4,9,5,7,4,6,4,5,5,5,6,4,6,3,6,2,6,1,6,-1,5,-2,5,-3,5,-4,5,-5,5,-6,5,-7,5,-8,4,-9,5,-10,6,-11,6,-11,7,-12,7,-13,8,-13,9,-14,9,-14,10,-15,10,-15,11,-16,11,-16,12,-17,12,-17,13,-17,14,-18,15,-17,15,-17,16,-16,16,-17,17,-16,17,-16,18,-16,19,-16,20,-17,21,-17,22,-16,23,-16,24,-15,24,-15,25,-15,26,-14,26,-14,27,-13,28,-12,28,-11,29,-10,29,-10,30,-10,31,-9,32,-9,33,-8,34,-7,34,-6,35,-6,36,-5,36,-5,35,-4,35,-3,35,-2,35,-1,36,0,36,1,36,1,37,3,37,5,37,6,37,7,37,8,37,10,37,11,37,11,36,11,35,10,34,11,34,11,33,13,33,14,33,15,32,16,31,17,31,18,31,19,30,20,31,20,32,21,33,22,33,23,33,23,32,24,32,25,32,26,32,27,31,28,31,29,31,30,31,31,32,32,31,33,31,34,31,35,32,34,32,35,32,35,33,35,34,36,35,36,36,36,37,35,37,34,36,33,36,32,37,31,37,30,36,29,37,28,37,27,38,26,38,27,39,26,39,27,40,29,40,29,41,31,41,32,42,34,42,35,42,37,41,38,41,40,41,42,42,41,43,40,43,39,44,38,45,37,45,38,46,38,47,39,47,38,47,37,47,36,47,35,46,36,45,37,45,36,45,35,45,34,44,33,45,34,45,32,45,33,46,34,46,33,46,32,46,32,47,31,47,30,46,30,45,29,45,29,44,28,43,28,42,29,41,28,41,27,41,26,40,26,41,25,41,24,41,24,40,23,40,23,39,24,39,24,38,23,38,23,37,23,36,22,36,22,37,21,38,21,39,20,39,20,40,19,40,19,41,20,42,19,42,18,42,18,43,17,43,16,44,15,44,15,45,14,45,14,46,13,46,12,45,13,44,14,44,14,43,15,42,16,42,17,41,18,41,18,40,17,40,16,40,17,39,16,38,16,39,16,40,15,40,15,41,14,41,13,41,12,42,11,42,11,43,10,44,9,44,8,44,7,44,7,43,5,43,3,43,3,42,2,41,1,41,0,40,0,39,0,38,-1,38,-1,37,-2,37,-3,37,-4,37,-5,36,-6,36,-7,37,-8,37,-9,37,-9,38,-10,39,-9,39,-9,40,-9,41,-9,42,-9,43,-8,44,-7,44,-5,44,-4,43,-2,43,-1,44,-1,46,-2,47,-3,48,-4,48,-5,49,-3,49,-2,49,-2,50,-1,49,1,50,2,51,3,51,4,52,5,53,6,54,7,53,7,54,8,54,9,54,9,55,8,56,8,57,9,57,10,57,11,58,11,57,10,57,11,56,10,56,10,55,11,54,12,54,13,54,14,54,15,54,16,55,18,55,19,55,19,54,20,54,20,55,21,55,21,56,21,57,22,57,23,58,23,57,24,57,24,58,23,59,25,59,26,60,27,59,28,59,29,60,28,61,26,60,24,60,23,60,22,60,21,61,22,62,21,63,22,63,22,64,25,65,25,66,24,66,22,66,21,65,21,64,20,64,18,63,17,61,18,61,19,60,18,59,17,59,16,57,16,56,15,56,14,55,13,55,13,56,12,57,11,59,10,59,8,58,7,58,6,59,5,60,5,62,6,63,9,63,11,64,12,66,15,68,16,69,19,70,21,70,23,70,25,71,26,71,28,71,31,70,30,70,31,70,32,70,34,69,37,69,40,68,41,67,40,66,38,66,34,67,33,67,35,66,35,64,36,64,37,64,37,65,40,65,42,66,43,66,44,66,45,67,44,67,44,68,43,69,46,68,47,68,46,68,46,67,48,67,48,68,50,68,54,69,53,68,55,68,57,68,59,69,60,68,61,69,60,70,61,70,64,70,65,69,69,68,69,69,68,69,67,69,67,70,67,71,69,72,69,73,70,73,73,73,73,72,72,71,73,70,73,69,74,68,73,68,71,66,72,66,73,67,74,67,75,68,74,68,75,69,74,69,74,70,74,71,73,71,75,72,75,73,76,72,75,71,76,71,76,72,78,72,80,72,82,72,81,73,81,74,82,74,85,74,87,74,86,74,87,75,88,75,90,76,93,76,96,76,97,76,99,76,101,76,101,77,102,77,104,78,106,77,105,77,107,77,null,49,41,50,41,50,40,49,39,49,38,50,37,51,37,52,37,54,37,54,38,54,39,53,39,53,40,53,41,54,41,55,41,54,42,53,42,53,41,53,42,52,42,53,42,53,43,51,43,51,44,50,44,50,45,51,45,52,45,53,45,53,46,53,47,52,47,51,47,50,47,49,46,48,46,47,45,48,44,47,43,49,42,49,41,null,-94,78,-94,77,-96,78,-94,78,null,-110,78,-112,77,-114,78,-113,78,-111,78,-110,78,null,25,78,22,77,21,78,23,78,25,78,null,-110,79,-111,78,-113,78,-113,79,-112,79,-111,79,-110,79,null,-96,78,-97,78,-98,78,-99,78,-99,79,-97,79,-96,78,null,-100,78,-101,78,-103,78,-105,78,-104,79,-105,79,-104,79,-101,79,-100,78,null,105,78,99,78,101,79,102,79,103,79,105,79,105,78,null,18,80,22,79,19,79,18,78,17,77,16,77,14,77,15,78,13,78,11,79,10,80,13,80,14,80,15,80,16,80,17,80,18,80,null,25,80,27,80,26,80,23,79,20,80,18,80,17,80,20,81,22,80,23,81,25,80,null,51,81,50,80,49,80,48,80,47,80,47,81,45,81,47,81,48,81,49,81,50,81,52,81,51,81,null,100,79,98,79,95,79,93,79,93,80,91,80,94,81,96,81,98,81,100,80,100,79,null,-87,80,-86,79,-87,79,-89,78,-91,78,-93,78,-94,79,-93,79,-95,79,-96,80,-97,80,-96,81,-95,81,-94,81,-95,81,-92,81,-91,81,-89,81,-88,80,-87,80,null,-68,83,-66,83,-64,83,-62,83,-62,82,-64,82,-67,82,-68,82,-65,82,-68,81,-69,81,-71,80,-73,80,-74,79,-77,79,-76,79,-75,79,-76,78,-78,78,-80,77,-78,77,-81,76,-83,76,-86,76,-88,76,-89,76,-90,77,-88,77,-88,78,-85,78,-86,78,-88,78,-87,79,-85,79,-87,80,-84,80,-83,80,-82,80,-84,81,-88,81,-89,81,-90,81,-91,82,-92,82,-90,82,-89,82,-87,82,-86,83,-84,83,-83,82,-82,83,-81,83,-79,83,-76,83,-73,83,-71,83,-68,83,null,-27,84,-21,83,-23,82,-27,82,-32,82,-31,82,-28,82,-25,82,-23,82,-22,82,-23,81,-21,82,-16,82,-13,82,-12,81,-16,81,-17,80,-20,80,-18,80,-19,79,-20,79,-20,78,-18,77,-20,77,-22,77,-20,76,-20,75,-21,75,-19,74,-22,74,-20,74,-21,73,-22,73,-24,73,-22,73,-22,72,-24,73,-25,72,-23,72,-22,71,-24,70,-24,71,-26,71,-25,71,-26,70,-24,70,-22,70,-25,69,-28,68,-31,68,-32,68,-33,68,-34,67,-36,66,-37,66,-38,66,-40,65,-41,65,-41,64,-41,63,-43,63,-42,62,-43,61,-43,60,-45,60,-46,61,-48,61,-49,61,-50,62,-52,64,-52,65,-54,66,-53,67,-54,67,-53,68,-51,69,-51,70,-52,70,-53,69,-55,70,-54,71,-53,71,-51,71,-53,71,-54,72,-55,71,-56,72,-55,73,-56,74,-57,75,-59,75,-59,76,-61,76,-63,76,-66,76,-69,76,-70,76,-71,77,-69,77,-67,77,-71,78,-73,78,-69,79,-66,79,-65,80,-68,80,-67,81,-64,81,-62,81,-63,82,-60,82,-57,82,-54,82,-53,82,-50,82,-48,82,-47,82,-45,82,-47,82,-47,83,-43,83,-40,83,-39,84,-35,84,-27,84];

        function resize() {
            var vw = window.innerWidth * 0.9; // 5% margin each side
            var vh = window.innerHeight;
            var headerH = document.getElementById('header').offsetHeight;
            var footerH = document.getElementById('footer').offsetHeight || 80;
            var gapSize = 16;
            var availH = vh - headerH - footerH - gapSize * 2;

            // ~2:1 aspect ratio (equirectangular), stretched 15% taller
            var w, h;
            if (vw / availH > 2) {
                h = availH;
                w = h * 2;
            } else {
                w = vw;
                h = w / 2;
            }
            h = Math.min(h * 1.15, availH);

            gap1.style.height = gapSize + 'px';
            gap2.style.height = gapSize + 'px';
            mapWrap.style.width = w + 'px';
            mapWrap.style.height = h + 'px';
            canvas.style.width = w + 'px';
            canvas.style.height = h + 'px';
            canvas.width = Math.round(w * dpr);
            canvas.height = Math.round(h * dpr);
            draw();
        }

        function draw() {
            var w = canvas.width;
            var h = canvas.height;
            ctx.clearRect(0, 0, w, h);

            // Border
            ctx.strokeStyle = 'rgba(0, 0, 0, 0.25)';
            ctx.lineWidth = 1 * dpr;
            ctx.strokeRect(0, 0, w, h);

            // Timezone lines (24 zones, 15° apart)
            ctx.save();
            ctx.setLineDash([2 * dpr, 4 * dpr]);
            ctx.strokeStyle = 'rgba(0, 0, 0, 0.15)';
            ctx.lineWidth = 1 * dpr;
            ctx.font = 'bold ' + Math.round(13 * dpr) + 'px Courier New';
            ctx.fillStyle = 'rgba(0, 0, 0, 0.3)';
            ctx.textAlign = 'center';
            for (var tz = -12; tz <= 11; tz++) {
                // Berlin is UTC+1, offset from Berlin = tz - 1
                var lng = tz * 15;
                var x = projX(lng);
                ctx.beginPath();
                ctx.moveTo(x, 0);
                ctx.lineTo(x, h);
                ctx.stroke();
                var offset = tz - 1;
                var label = (offset >= 0 ? '+' : '') + offset;
                ctx.fillText(label, x, 12 * dpr);
            }
            ctx.restore();

            // Continent outlines
            ctx.save();
            ctx.strokeStyle = 'rgba(0, 0, 0, 0.12)';
            ctx.lineWidth = 1 * dpr;
            ctx.beginPath();
            var first = true;
            for (var ci = 0; ci < W.length; ci += 2) {
                if (W[ci] === null) { first = true; ci--; continue; }
                var cx = projX(W[ci]);
                var cy = projY(W[ci + 1]);
                if (first) { ctx.moveTo(cx, cy); first = false; }
                else { ctx.lineTo(cx, cy); }
            }
            ctx.stroke();
            ctx.restore();

            for (var i = 0; i < locations.length; i++) {
                var loc = locations[i];
                var lat = loc[0], lng = loc[1], count = loc[2];

                // Equirectangular projection
                var x = projX(lng);
                var y = projY(lat);

                // Radius scales with sqrt(count): 1.5px–6px (CSS pixels)
                var r = Math.min(6, Math.max(1.5, Math.sqrt(count) * 1.5)) * dpr;
                var isHover = (hoverLoc === loc);
                if (isHover) r *= 1.2;

                // Color interpolates black (low count) to red (high count)
                var t = Math.min(1, Math.sqrt(count) / 6);
                var red = Math.round(t * 200);
                var alpha = Math.min(0.9, 0.5 + Math.sqrt(count) * 0.05);

                if (isHover) {
                    ctx.beginPath();
                    ctx.arc(x, y, r + 3 * dpr, 0, Math.PI * 2);
                    ctx.strokeStyle = 'rgba(0, 0, 0, 0.6)';
                    ctx.lineWidth = 1 * dpr;
                    ctx.stroke();
                }

                ctx.beginPath();
                ctx.arc(x, y, r, 0, Math.PI * 2);
                ctx.fillStyle = 'rgba(' + red + ', 0, 0, ' + alpha + ')';
                ctx.fill();

                if (filterCountry && loc[3] === filterCountry) {
                    ctx.beginPath();
                    ctx.arc(x, y, r + 2 * dpr, 0, Math.PI * 2);
                    ctx.strokeStyle = 'rgba(255, 255, 255, 0.9)';
                    ctx.lineWidth = 1.5 * dpr;
                    ctx.stroke();
                }
            }
        }

        function update(data) {
            locations = data.locations || [];
            total = data.total || 0;
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            var html = total.toLocaleString() + ' knocks';
            if (data.visitor_count) {
                html += ' \u00b7 ' + data.visitor_count.toLocaleString() + ' visitors';
            }
            if (data.since) {
                var d = new Date(data.since);
                var isMobile = window.innerWidth <= 600;
                var zoomHint = isMobile ? '' : ' (scroll to zoom, double-click to reset)';
                html += ' \u00b7 <a id="show-poems" href="#">Display poems</a> since ' + months[d.getUTCMonth()] + ' ' + d.getUTCDate() + ', ' + d.getUTCFullYear() + zoomHint;
            } else {
                html += ' \u00b7 <a id="show-poems" href="#">Display poems</a>';
            }
            counter.innerHTML = html;
            document.getElementById('show-poems').onclick = function(e) {
                e.preventDefault();
                poemHidden = false;
                if (filterCountry) {
                    filterCountry = null;
                    poems = unfilteredPoems;
                    poemTotal = unfilteredTotal;
                    poemIdx = unfilteredIdx;
                    draw();
                }
                if (poems.length > 0) {
                    showPoem(poemIdx);
                } else {
                    fetchPoems(0, false, function() {
                        if (poems.length > 0) showPoem(0);
                    });
                }
            };
            draw();
        }

        function poll() {
            fetch('/_api/locations')
                .then(function(r) { return r.json(); })
                .then(update)
                .catch(function() {});
        }

        // --- Country code to name ---
        var CC = {AF:'Afghanistan',AL:'Albania',DZ:'Algeria',AO:'Angola',AR:'Argentina',AM:'Armenia',AU:'Australia',AT:'Austria',AZ:'Azerbaijan',BD:'Bangladesh',BY:'Belarus',BE:'Belgium',BJ:'Benin',BO:'Bolivia',BA:'Bosnia and Herzegovina',BR:'Brazil',BG:'Bulgaria',BF:'Burkina Faso',KH:'Cambodia',CM:'Cameroon',CA:'Canada',CL:'Chile',CN:'China',CO:'Colombia',CD:'Congo',CR:'Costa Rica',HR:'Croatia',CU:'Cuba',CY:'Cyprus',CZ:'Czechia',DK:'Denmark',DO:'Dominican Republic',EC:'Ecuador',EG:'Egypt',SV:'El Salvador',EE:'Estonia',ET:'Ethiopia',FI:'Finland',FR:'France',GE:'Georgia',DE:'Germany',GH:'Ghana',GR:'Greece',GT:'Guatemala',GN:'Guinea',HT:'Haiti',HN:'Honduras',HK:'Hong Kong',HU:'Hungary',IS:'Iceland',IN:'India',ID:'Indonesia',IR:'Iran',IQ:'Iraq',IE:'Ireland',IL:'Israel',IT:'Italy',JM:'Jamaica',JP:'Japan',JO:'Jordan',KZ:'Kazakhstan',KE:'Kenya',KP:'North Korea',KR:'South Korea',KW:'Kuwait',KG:'Kyrgyzstan',LA:'Laos',LV:'Latvia',LB:'Lebanon',LY:'Libya',LT:'Lithuania',LU:'Luxembourg',MG:'Madagascar',MY:'Malaysia',ML:'Mali',MX:'Mexico',MD:'Moldova',MN:'Mongolia',ME:'Montenegro',MA:'Morocco',MZ:'Mozambique',MM:'Myanmar',NP:'Nepal',NL:'Netherlands',NZ:'New Zealand',NI:'Nicaragua',NE:'Niger',NG:'Nigeria',NO:'Norway',OM:'Oman',PK:'Pakistan',PA:'Panama',PY:'Paraguay',PE:'Peru',PH:'Philippines',PL:'Poland',PT:'Portugal',QA:'Qatar',RO:'Romania',RU:'Russia',RW:'Rwanda',SA:'Saudi Arabia',SN:'Senegal',RS:'Serbia',SC:'Seychelles',SG:'Singapore',SK:'Slovakia',SI:'Slovenia',SO:'Somalia',ZA:'South Africa',ES:'Spain',LK:'Sri Lanka',SD:'Sudan',SE:'Sweden',CH:'Switzerland',SY:'Syria',TW:'Taiwan',TJ:'Tajikistan',TZ:'Tanzania',TH:'Thailand',TN:'Tunisia',TR:'Turkey',TM:'Turkmenistan',UA:'Ukraine',AE:'United Arab Emirates',GB:'United Kingdom',US:'United States',UY:'Uruguay',UZ:'Uzbekistan',VE:'Venezuela',VN:'Vietnam',YE:'Yemen',ZM:'Zambia',ZW:'Zimbabwe'};

        // --- Poem card with browsing & pagination ---
        var poems = [];         // currently displayed poems (paginated, possibly filtered)
        var poemTotal = 0;      // total poems available on server (for current filter)
        var poemIdx = 0;        // current index (0 = newest)
        var poemLoading = false; // fetch in progress
        var filterCountry = null;
        var poemHidden = false;
        // Cache of unfiltered state so we can restore without re-fetching
        var unfilteredPoems = [];
        var unfilteredTotal = 0;
        var unfilteredIdx = 0;
        var PAGE_SIZE = 50;

        function cleanPoemForCard(raw) {
            if (!raw) return '';
            var s = raw.replace(/\\r\\n/g, '\n').replace(/\r\n/g, '\n');

            // --- Whole-entry rejection ---
            var t = s.replace(/^(json|php|html|http|xml|sql|css|plaintext|text)\s*\n/i, '').trim();
            if (/^(GET |HEAD |POST |PUT |DELETE |OPTIONS )/i.test(t)) return '';
            if (/^> (GET|HEAD|POST|PUT) /i.test(t)) return '';
            if (/^\s*[\[{]/.test(t) && /[\]}]\s*$/.test(t)) return '';
            if (/^<\?php/i.test(t)) return '';
            if (/^#\w|^class \w|^function \w|^import |^require |^package /i.test(t)) return '';

            // --- Block-level stripping ---
            // Echoed prompt context
            s = s.replace(/^[\s\S]*?(?:A (?:visitor|scanner) just[\s\S]*?\n\n)/i, '');
            // "Visitor: 1.2.3.4" preamble block
            s = s.replace(/^Visitor:[\s\S]*?\n\n/i, '');
            // "Your work is to..." / "Your poem must..." instruction preamble
            s = s.replace(/^Your (work|poem|task|response)[\s\S]*?\n\n/i, '');
            // Everything before last --- separator (instruction preamble)
            s = s.replace(/^[\s\S]*\n---\n/, '');
            // Structured data fragments
            s = s.replace(/<[a-z_-]+>\s*=\s*"[^"]*"/gi, '');
            s = s.replace(/\[[\d.]+\]/g, '');
            // Bold headers and plain "Honeypoet's Poem:" lines
            s = s.replace(/\*\*Honeypoet'?s?\s+(response|poem):?\*\*:?/gi, '');
            s = s.replace(/^Honeypoet'?s?\s+(response|poem):?\s*\n/gim, '');
            // JSON blocks embedded in text
            s = s.replace(/\{[\s\S]*?\}/g, function(m) { return /\"(path|method|from|poem)\"/.test(m) ? '' : m; });
            // URLs
            s = s.replace(/https?:\/\/\S+/g, '');

            // --- Line-level filtering ---
            var pLines = s.split('\n');
            var clean = [];
            for (var pi = 0; pi < pLines.length; pi++) {
                var pl = pLines[pi].replace(/[\u{1F000}-\u{1FFFF}\u{2600}-\u{27BF}\u{FE00}-\u{FE0F}\u{200D}\u{20E3}\u{E0020}-\u{E007F}]/gu, '').trim();
                if (!pl || /^[-<>*#=_`~]+$/.test(pl)) continue;
                if (/^```/.test(pl)) continue;
                var lo = pl.toLowerCase();
                // Instruction echoes (broad)
                if (/^(use |make |no |write |here is |here's |give |close |end |answer |imagine |let |guidelines|instructions|stay |convey |conclude |maintain |keep |ensure |avoid |include |the (poem|haiku|visit|letter|drawer|log|door)|format |do not |don't |be (sure|philosophical|creative)|your (answer|poem|response)|remember |try |consider |think |in het |auf deutsch|en fran|embrace |and if you|and remember|note:?$|visiting |visitor |visited |knocked |the .+ should|the .+ captures|the .+ lasted|the .+ contains|the .+ reads)/i.test(lo)) continue;
                // Bullet-point instructions
                if (/^- /i.test(pl) && !/[,.]$/.test(pl)) continue;
                // Bold-wrapped lines
                if (/^\*\*.+\*\*:?\s*$/.test(pl)) continue;
                // Code fence language tags
                if (/^(json|php|html|http|xml|sql|css|plaintext|text)\s*$/i.test(pl)) continue;
                // HTTP request/header lines
                if (/^(GET|HEAD|POST|PUT|DELETE|OPTIONS) \//i.test(pl)) continue;
                if (/^(Host|User-Agent|Accept|Referer|Connection|Upgrade|Content-|Cookie|Authorization|Cache-|Pragma|Origin|DNT|Sec-|X-|Via|From:|If-)[\w-]*:\s*/i.test(pl)) continue;
                // Code lines
                if (/^<\?php|^<\/?\w+[\s>\/]|^<!\w|;\s*$|^\$\w|^echo |^session_|^document\.|^function\s*[\(\{]|^location\.|^class \w|^public |^private |^return |^var |^const |^let |^import /i.test(pl)) continue;
                // Path/method references as prose
                if (/^\/[\w.-]+\.php/i.test(pl)) continue;
                if (/^(from|path|method|ip|host|visitor|request|response)\s*:/i.test(lo)) continue;
                if (/^your (work|poem|task|response)[ :]/i.test(lo)) continue;
                if (/^(the )?honeypoet'?s?\s+(poem|response|says)/i.test(lo)) continue;
                if (/^(i await|no other text|honeypoet,?\s+(begin|your))/i.test(lo)) continue;
                // Tagline/title instructions
                if (/^tagline:|^title:|^poem:/i.test(lo)) continue;
                // WP/tech references as standalone lines
                if (/^(wp |wordpress )/i.test(lo) && pl.length < 30) continue;
                clean.push(pl);
            }
            while (clean.length && clean[0] === '') clean.shift();
            while (clean.length && clean[clean.length - 1] === '') clean.pop();
            s = clean.join('\n');
            s = s.replace(/`/g, '').replace(/\*\*/g, '').replace(/[\u{1F000}-\u{1FFFF}\u{2600}-\u{27BF}\u{FE00}-\u{FE0F}\u{200D}\u{20E3}\u{E0020}-\u{E007F}]/gu, '');
            // Mask middle octets of IP addresses (192.x.x.42)
            s = s.replace(/(\d{1,3})\.\d{1,3}\.\d{1,3}\.(\d{1,3})/g, '$1.x.x.$2');
            // Collapse runs of blank lines
            s = s.replace(/\n{3,}/g, '\n\n');
            s = s.trim();
            // Keep at most 3 stanzas / 12 lines
            var stanzas = s.split('\n\n');
            if (stanzas.length > 3) s = stanzas.slice(0, 3).join('\n\n');
            var lines = s.split('\n');
            if (lines.length > 12) s = lines.slice(0, 12).join('\n');
            // Too short to be a real poem
            if (s.length < 20) return '';
            return s;
        }

        function esc(t) { return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        function showPoem(idx) {
            if (poems.length === 0) return;
            idx = Math.max(0, Math.min(idx, poems.length - 1));
            poemIdx = idx;
            var p = poems[idx];
            var verseStyle = p.isCode ? 'font-family:\"Courier New\",monospace;font-size:12px;line-height:1.5' : '';
            var atEnd = (idx >= poems.length - 1);
            var hasMore = (poems.length < poemTotal);
            poemCard.innerHTML =
                '<button id="poem-hide" title="Hide (Esc)">\u00d7</button>' +
                '<div class="attrib">' + esc(p.attrib) + '</div>' +
                (p.meta ? '<div class="meta">' + esc(p.meta) + '</div>' : '') +
                '<div class="verse" style="' + verseStyle + '">' + esc(p.text) + '</div>' +
                '<div id="poem-nav">' +
                    '<button id="btn-before">' + (atEnd && hasMore ? '\u25c0 load more' : '\u25c0 before') + '</button>' +
                    '<span class="pos">' + (idx + 1) + ' / ' + poemTotal + '</span>' +
                    '<button id="btn-after">after \u25b6</button>' +
                '</div>';
            document.getElementById('btn-before').disabled = (atEnd && !hasMore);
            document.getElementById('btn-after').disabled = (idx <= 0);
            document.getElementById('btn-before').onclick = function() {
                if (poemIdx >= poems.length - 1 && poems.length < poemTotal) {
                    loadMore();
                } else {
                    showPoem(poemIdx + 1);
                }
            };
            document.getElementById('btn-after').onclick = function() { showPoem(poemIdx - 1); };
            document.getElementById('poem-hide').onclick = function() { poemHidden = true; poemCard.classList.remove('visible'); };
            poemCard.classList.add('visible');
        }

        // Convert raw API entries to display objects
        function processEntries(entries) {
            var result = [];
            for (var i = 0; i < entries.length; i++) {
                var v = entries[i];
                var isCode = (v.response_type === 'llm_code');
                var text = isCode ? (v.response_content || '').trim() : cleanPoemForCard(v.response_content);
                if (!text) continue;
                var nameStr = v.visitor_name || 'Someone';
                if (v.visitor_behavior) {
                    nameStr += ', the ' + v.visitor_behavior.charAt(0).toUpperCase() + v.visitor_behavior.slice(1);
                }
                var countryName = v.country ? (CC[v.country] || v.country) : '';
                var path = v.path || '/';
                var cat = v.attack_category || '';
                // Derive a short archetype label
                var archetype = cat === 'visitor' ? 'gallery visit' : (cat ? cat.replace(/_/g, ' ') : path);
                // Format timestamp as HH:MM UTC
                var ts = v.timestamp || '';
                var timeStr = '';
                if (ts) {
                    var td = new Date(ts);
                    timeStr = ('0' + td.getUTCHours()).slice(-2) + ':' + ('0' + td.getUTCMinutes()).slice(-2) + ' UTC';
                }
                result.push({
                    id: v.id,
                    country: v.country || '',
                    isCode: isCode,
                    attrib: 'For ' + nameStr + (countryName ? ' from ' + countryName : ''),
                    meta: (archetype ? archetype : '') + (timeStr ? ' \u00b7 ' + timeStr : ''),
                    text: text
                });
            }
            return result;
        }

        // Fetch poems from API with pagination
        function fetchPoems(offset, append, onDone) {
            if (poemLoading) return;
            poemLoading = true;
            var url = '/_api/poems?limit=' + PAGE_SIZE + '&offset=' + offset;
            if (filterCountry) url += '&country=' + encodeURIComponent(filterCountry);
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    poemLoading = false;
                    var fresh = processEntries(data.poems || []);
                    poemTotal = data.total || 0;
                    if (append) {
                        poems = poems.concat(fresh);
                    } else {
                        poems = fresh;
                    }
                    if (onDone) onDone();
                })
                .catch(function() { poemLoading = false; });
        }

        // Load more poems (triggered by "before" button at end of list)
        function loadMore() {
            var prevLen = poems.length;
            fetchPoems(poems.length, true, function() {
                if (poems.length > prevLen) {
                    showPoem(prevLen); // show first newly loaded poem
                } else {
                    showPoem(poemIdx); // refresh display (button state)
                }
            });
        }

        // Poll for new poems (check if newest ID changed)
        var lastTopId = 0;
        function pollFeed() {
            var url = '/_api/poems?limit=10&offset=0';
            if (filterCountry) url += '&country=' + encodeURIComponent(filterCountry);
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var fresh = processEntries(data.poems || []);
                    poemTotal = data.total || poemTotal;
                    if (fresh.length === 0) return;
                    var topId = fresh[0].id;
                    if (topId === lastTopId) return;
                    lastTopId = topId;
                    // Prepend any truly new poems
                    var existingIds = {};
                    for (var i = 0; i < poems.length; i++) existingIds[poems[i].id] = true;
                    var prepend = [];
                    for (var j = 0; j < fresh.length; j++) {
                        if (!existingIds[fresh[j].id]) prepend.push(fresh[j]);
                    }
                    if (prepend.length > 0) {
                        poems = prepend.concat(poems);
                        poemIdx += prepend.length; // keep position stable
                    }
                    if (!poemHidden && poems.length > 0 && poemIdx === prepend.length) {
                        showPoem(0); // auto-advance to newest if user was on newest
                    }
                })
                .catch(function() {});
        }

        function findNearestDot(mx, my) {
            var best = null, bestDist = 10 * dpr;
            for (var i = 0; i < locations.length; i++) {
                var loc = locations[i];
                var x = projX(loc[1]);
                var y = projY(loc[0]);
                var dx = mx - x, dy = my - y;
                var dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < bestDist) {
                    bestDist = dist;
                    best = loc;
                }
            }
            return best;
        }

        canvas.addEventListener('wheel', function(e) {
            e.preventDefault();
            var rect = canvas.getBoundingClientRect();
            var mx = (e.clientX - rect.left) * dpr;
            var my = (e.clientY - rect.top) * dpr;
            var mLng = unprojLng(mx);
            var mLat = unprojLat(my);
            var factor = e.deltaY < 0 ? 1.25 : 1 / 1.25;
            var newZoom = Math.max(1, Math.min(10, zoom * factor));
            if (newZoom !== zoom) {
                zoom = newZoom;
                panLng = mLng - (mx / canvas.width * 360 - 180) / zoom;
                panLat = mLat + (my / canvas.height * 180 - 90) / zoom;
                clampPan();
                draw();
            }
        }, { passive: false });

        canvas.addEventListener('mousedown', function(e) {
            if (zoom > 1) {
                dragging = true;
                dragMoved = false;
                var rect = canvas.getBoundingClientRect();
                dragStartX = (e.clientX - rect.left) * dpr;
                dragStartY = (e.clientY - rect.top) * dpr;
                dragStartLng = panLng;
                dragStartLat = panLat;
            }
        });

        canvas.addEventListener('mouseup', function() { dragging = false; });

        canvas.addEventListener('mousemove', function(e) {
            var rect = canvas.getBoundingClientRect();
            var mx = (e.clientX - rect.left) * dpr;
            var my = (e.clientY - rect.top) * dpr;
            if (dragging) {
                var dx = mx - dragStartX;
                var dy = my - dragStartY;
                if (Math.abs(dx) > 2 * dpr || Math.abs(dy) > 2 * dpr) dragMoved = true;
                if (dragMoved) {
                    panLng = dragStartLng - dx / canvas.width * 360 / zoom;
                    panLat = dragStartLat + dy / canvas.height * 180 / zoom;
                    clampPan();
                    tooltip.style.display = 'none';
                    canvas.style.cursor = 'grabbing';
                    draw();
                    return;
                }
            }
            var loc = findNearestDot(mx, my);
            var changed = (hoverLoc !== loc);
            hoverLoc = loc;
            if (loc) {
                var city = loc[4] || '';
                var cc = loc[3] || '';
                var cname = cc ? (CC[cc] || cc) : '';
                var label = (city && cname) ? city + ', ' + cname : (cname || city || 'Unknown');
                label += ' \u2014 ' + loc[2] + (loc[2] === 1 ? ' knock' : ' knocks');
                tooltip.textContent = label;
                tooltip.style.display = 'block';
                tooltip.style.left = (e.clientX - rect.left + 12) + 'px';
                tooltip.style.top = (e.clientY - rect.top - 24) + 'px';
                canvas.style.cursor = 'pointer';
            } else {
                tooltip.style.display = 'none';
                canvas.style.cursor = zoom > 1 ? 'grab' : '';
            }
            if (changed) draw();
        });

        canvas.addEventListener('mouseleave', function() {
            tooltip.style.display = 'none';
            canvas.style.cursor = '';
            dragging = false;
            if (hoverLoc) { hoverLoc = null; draw(); }
        });

        canvas.addEventListener('click', function(e) {
            if (dragMoved) { dragMoved = false; return; }
            var rect = canvas.getBoundingClientRect();
            var mx = (e.clientX - rect.left) * dpr;
            var my = (e.clientY - rect.top) * dpr;
            var loc = findNearestDot(mx, my);
            if (loc && loc[3]) {
                if (dotHint && !dotHint.classList.contains('hidden')) {
                    dotHint.classList.add('hidden');
                    setTimeout(function() { dotHint.style.display = 'none'; }, 800);
                }
                var cc = loc[3];
                poemHidden = false;
                if (filterCountry === cc) {
                    // Clear filter — restore cached unfiltered state
                    filterCountry = null;
                    poems = unfilteredPoems;
                    poemTotal = unfilteredTotal;
                    poemIdx = unfilteredIdx;
                    if (poems.length > 0) showPoem(poemIdx);
                } else {
                    // Save unfiltered state before filtering
                    if (!filterCountry) {
                        unfilteredPoems = poems;
                        unfilteredTotal = poemTotal;
                        unfilteredIdx = poemIdx;
                    }
                    filterCountry = cc;
                    fetchPoems(0, false, function() {
                        if (poems.length > 0) {
                            poemIdx = 0;
                            showPoem(0);
                        } else {
                            // No poems for this country — revert
                            filterCountry = null;
                            poems = unfilteredPoems;
                            poemTotal = unfilteredTotal;
                            poemIdx = unfilteredIdx;
                        }
                    });
                }
                draw();
            }
        });

        canvas.addEventListener('dblclick', function(e) {
            e.preventDefault();
            if (zoom > 1) {
                zoom = 1; panLng = 0; panLat = 0;
                draw();
            }
        });

        // Touch: pinch-to-zoom and two-finger pan
        var lastTouchDist = 0;
        var lastTouchMid = null;
        canvas.addEventListener('touchstart', function(e) {
            if (e.touches.length === 2) {
                e.preventDefault();
                var dx = e.touches[0].clientX - e.touches[1].clientX;
                var dy = e.touches[0].clientY - e.touches[1].clientY;
                lastTouchDist = Math.sqrt(dx * dx + dy * dy);
                lastTouchMid = { x: (e.touches[0].clientX + e.touches[1].clientX) / 2,
                                 y: (e.touches[0].clientY + e.touches[1].clientY) / 2 };
            }
        }, { passive: false });
        canvas.addEventListener('touchmove', function(e) {
            if (e.touches.length === 2) {
                e.preventDefault();
                var dx = e.touches[0].clientX - e.touches[1].clientX;
                var dy = e.touches[0].clientY - e.touches[1].clientY;
                var dist = Math.sqrt(dx * dx + dy * dy);
                if (lastTouchDist > 0) {
                    var scale = dist / lastTouchDist;
                    zoom = Math.max(1, Math.min(10, zoom * scale));
                    clampPan();
                    draw();
                }
                lastTouchDist = dist;
                // Pan with two fingers
                var mid = { x: (e.touches[0].clientX + e.touches[1].clientX) / 2,
                            y: (e.touches[0].clientY + e.touches[1].clientY) / 2 };
                if (lastTouchMid && zoom > 1) {
                    var mdx = (mid.x - lastTouchMid.x) * dpr;
                    var mdy = (mid.y - lastTouchMid.y) * dpr;
                    panLng -= mdx / canvas.width * 360 / zoom;
                    panLat += mdy / canvas.height * 180 / zoom;
                    clampPan();
                }
                lastTouchMid = mid;
            }
        }, { passive: false });
        canvas.addEventListener('touchend', function() {
            lastTouchDist = 0; lastTouchMid = null;
        });

        // Keyboard navigation
        var dotHint = document.getElementById('dot-hint');
        var isMobile = ('ontouchstart' in window) || window.innerWidth <= 600;
        dotHint.textContent = isMobile ? 'Tap a dot to read its poem' : 'Click a dot to read its poem';
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && poemCard.classList.contains('visible')) {
                poemHidden = true;
                poemCard.classList.remove('visible');
            } else if (e.key === 'ArrowLeft' && poemCard.classList.contains('visible')) {
                e.preventDefault();
                if (poemIdx >= poems.length - 1 && poems.length < poemTotal) {
                    loadMore();
                } else if (poemIdx < poems.length - 1) {
                    showPoem(poemIdx + 1);
                }
            } else if (e.key === 'ArrowRight' && poemCard.classList.contains('visible')) {
                e.preventDefault();
                if (poemIdx > 0) showPoem(poemIdx - 1);
            }
        });

        window.addEventListener('resize', resize);
        resize();
        poll();
        fetchPoems(0, false, function() {
            if (poems.length > 0 && !poemHidden) showPoem(0);
            lastTopId = poems.length > 0 ? poems[0].id : 0;
        });
        setInterval(poll, 30000);
        setInterval(pollFeed, 10000);
    })();
    </script>
</body>
</html>
HTML;
}

// ===========================================================================
// Museum page — "Our Visitors, Our Exhibits"
// ===========================================================================

function serve_museum(): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');

    echo <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>honeypoet — the museum</title>
    <meta name="description" content="The Honeypo(e)t Museum. Top visitors, weekly exhibitions, and the zeitgeist woven into verse.">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { background: #fff1e5; color: #222; }
        .museum {
            max-width: 680px; margin: 0 auto; padding: 48px 24px 60px;
            font-family: Georgia, serif; line-height: 1.6;
        }
        h1 {
            font-size: 26px; font-weight: normal; letter-spacing: 0.04em;
            text-align: center; margin-bottom: 4px;
        }
        .subtitle {
            text-align: center; font-style: italic; font-size: 14px;
            color: rgba(0,0,0,0.5); margin-bottom: 40px;
        }
        h2 {
            font-size: 18px; font-weight: normal; letter-spacing: 0.03em;
            margin: 44px 0 16px; padding-bottom: 6px;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        .intro {
            font-size: 14px; line-height: 1.7; color: rgba(0,0,0,0.65);
            margin-bottom: 32px;
        }

        /* Visitor bars */
        .stat-row {
            display: flex; align-items: center; margin: 6px 0; gap: 10px;
        }
        .stat-label {
            font-family: 'Courier New', monospace; font-size: 12px;
            width: 200px; flex-shrink: 0; color: #222;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .stat-bar {
            flex: 1; height: 16px; background: rgba(0,0,0,0.06);
            border-radius: 2px; overflow: hidden;
        }
        .stat-fill {
            height: 100%; background: rgba(0,0,0,0.22); border-radius: 2px;
            transition: width 0.6s ease;
        }
        .stat-count {
            font-family: 'Courier New', monospace; font-size: 11px;
            color: rgba(0,0,0,0.45); min-width: 50px; text-align: right;
        }

        /* Week tabs */
        .week-tabs {
            display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px;
        }
        .week-tab {
            font-family: 'Courier New', monospace; font-size: 12px;
            border: 1px solid rgba(0,0,0,0.2); background: none;
            padding: 4px 14px; border-radius: 3px; cursor: pointer;
            color: #222; transition: background 0.2s;
        }
        .week-tab:hover { background: rgba(0,0,0,0.05); }
        .week-tab.active { background: rgba(0,0,0,0.1); }

        /* Poem exhibit cards */
        .exhibit {
            background: rgba(242,229,217,0.93); border-radius: 6px;
            padding: 14px 18px 12px; margin: 12px 0;
            box-shadow: 0 3px 16px rgba(0,0,0,0.1);
        }
        .exhibit .attrib {
            font-size: 12px; font-style: italic; color: rgba(0,0,0,0.45);
            margin-bottom: 4px;
        }
        .exhibit .meta {
            font-family: 'Courier New', monospace; font-size: 10px;
            color: rgba(0,0,0,0.35); margin-bottom: 6px;
        }
        .exhibit .verse {
            font-size: 14px; line-height: 1.6; white-space: pre-line;
        }

        /* Summary stats */
        .stats-summary {
            font-family: 'Courier New', monospace; font-size: 12px;
            color: rgba(0,0,0,0.5); text-align: center;
            margin-bottom: 32px; line-height: 1.8;
        }
        .stats-summary .num {
            color: #222; font-size: 13px;
        }

        /* Preferred exhibitions — country sub-sections */
        .country-exhibit {
            background: rgba(242,229,217,0.93); border-radius: 6px;
            padding: 14px 18px 12px; margin: 12px 0;
        }
        .country-exhibit h3 {
            font-family: Georgia, serif; font-size: 15px; font-weight: normal;
            margin-bottom: 8px; color: #222;
        }
        .country-exhibit .cat-list {
            font-family: 'Courier New', monospace; font-size: 12px;
            color: rgba(0,0,0,0.6); line-height: 1.8;
        }

        /* Hourly chart */
        .hourly-grid {
            display: flex; align-items: flex-end; gap: 2px;
            height: 80px; margin: 16px 0 4px;
            padding: 0 2px;
        }
        .hourly-col {
            flex: 1; display: flex; flex-direction: column;
            align-items: center; justify-content: flex-end; height: 100%;
        }
        .hourly-bar {
            width: 100%; background: rgba(0,0,0,0.22); border-radius: 1px 1px 0 0;
            min-height: 2px; transition: height 0.6s ease;
        }
        .hourly-labels {
            display: flex; gap: 2px; padding: 0 2px;
        }
        .hourly-labels span {
            flex: 1; text-align: center;
            font-family: 'Courier New', monospace; font-size: 9px;
            color: rgba(0,0,0,0.35);
        }

        /* Footer */
        .museum-footer {
            text-align: center; margin-top: 48px; padding-top: 16px;
            border-top: 1px solid rgba(0,0,0,0.1);
            font-family: 'Courier New', monospace; font-size: 11px;
            color: rgba(0,0,0,0.4);
        }
        .museum-footer a {
            color: rgba(0,0,0,0.5); text-decoration: none;
        }
        .museum-footer a:hover { color: rgba(0,0,0,0.7); }

        /* Loading */
        .loading {
            text-align: center; font-style: italic; font-size: 14px;
            color: rgba(0,0,0,0.4); padding: 40px 0;
        }

        /* Empty state */
        .empty {
            font-style: italic; font-size: 13px;
            color: rgba(0,0,0,0.35); padding: 12px 0;
        }

        @media (max-width: 600px) {
            .museum { padding: 24px 16px 40px; }
            h1 { font-size: 20px; }
            .stat-label { min-width: 100px; font-size: 11px; }
            .exhibit { padding: 12px 14px 10px; }
            .exhibit .verse { font-size: 13px; }
        }
    </style>
</head>
<body>
    <div class="museum">
        <h1>The Honeypo(e)t Museum</h1>
        <div class="subtitle">Where the internet's background radiation becomes art.</div>

        <div id="summary" class="stats-summary"></div>

        <p class="intro">
            Every day, machines knock on every door they can find &mdash; probing, scanning, testing.
            They come from everywhere. They never stop. And here, every knock becomes a poem.
            Welcome to the museum. These are our visitors, our exhibitions, and the spirit of the times woven into verse.
        </p>

        <h2>Top Countries</h2>
        <div id="countries"><div class="loading">Loading&hellip;</div></div>

        <h2 style="margin-top:32px">Top Cities</h2>
        <div id="cities"></div>

        <h2>This Week's Exhibition</h2>
        <div id="week-tabs" class="week-tabs"></div>
        <div id="exhibits"></div>

        <h2>Museum Engagement Report</h2>
        <p class="intro">How our visitors behave &mdash; their temperaments, their preferred exhibitions, how long they linger, and when the halls are busiest.</p>

        <h2 style="margin-top:32px">Visitor Profiles</h2>
        <p class="intro">Every visitor has a temperament. Some rush through every room, others return day after day to the same painting.</p>
        <div id="behaviors"><div class="loading">Loading&hellip;</div></div>

        <h2 style="margin-top:32px">Preferred Exhibitions</h2>
        <p class="intro">What draws each nation's visitors. The exhibitions they return to most.</p>
        <div id="preferred-exhibits"></div>

        <h2 style="margin-top:32px">Average Stay</h2>
        <p class="intro">Average visits per visitor, by country. Some pass through once; others settle in.</p>
        <div id="avg-stay"></div>

        <h2 style="margin-top:32px">Opening Hours</h2>
        <p class="intro">When the museum is busiest. The internet never sleeps, but it does have habits.</p>
        <div id="hourly-chart"></div>

        <div class="museum-footer">
            <a href="/">Return to the Gallery</a> &middot;
            &copy; 2026 M. Quest &middot;
            <a href="https://github.com/vrontier/honeypoet">source</a>
        </div>
    </div>

    <script>
    (function() {
        var CC = {AF:'Afghanistan',AL:'Albania',DZ:'Algeria',AO:'Angola',AR:'Argentina',AM:'Armenia',AU:'Australia',AT:'Austria',AZ:'Azerbaijan',BD:'Bangladesh',BY:'Belarus',BE:'Belgium',BJ:'Benin',BO:'Bolivia',BA:'Bosnia and Herzegovina',BR:'Brazil',BG:'Bulgaria',BF:'Burkina Faso',KH:'Cambodia',CM:'Cameroon',CA:'Canada',CL:'Chile',CN:'China',CO:'Colombia',CD:'Congo',CR:'Costa Rica',HR:'Croatia',CU:'Cuba',CY:'Cyprus',CZ:'Czechia',DK:'Denmark',DO:'Dominican Republic',EC:'Ecuador',EG:'Egypt',SV:'El Salvador',EE:'Estonia',ET:'Ethiopia',FI:'Finland',FR:'France',GE:'Georgia',DE:'Germany',GH:'Ghana',GR:'Greece',GT:'Guatemala',GN:'Guinea',HT:'Haiti',HN:'Honduras',HK:'Hong Kong',HU:'Hungary',IS:'Iceland',IN:'India',ID:'Indonesia',IR:'Iran',IQ:'Iraq',IE:'Ireland',IL:'Israel',IT:'Italy',JM:'Jamaica',JP:'Japan',JO:'Jordan',KZ:'Kazakhstan',KE:'Kenya',KP:'North Korea',KR:'South Korea',KW:'Kuwait',KG:'Kyrgyzstan',LA:'Laos',LV:'Latvia',LB:'Lebanon',LY:'Libya',LT:'Lithuania',LU:'Luxembourg',MG:'Madagascar',MY:'Malaysia',ML:'Mali',MX:'Mexico',MD:'Moldova',MN:'Mongolia',ME:'Montenegro',MA:'Morocco',MZ:'Mozambique',MM:'Myanmar',NP:'Nepal',NL:'Netherlands',NZ:'New Zealand',NI:'Nicaragua',NE:'Niger',NG:'Nigeria',NO:'Norway',OM:'Oman',PK:'Pakistan',PA:'Panama',PY:'Paraguay',PE:'Peru',PH:'Philippines',PL:'Poland',PT:'Portugal',QA:'Qatar',RO:'Romania',RU:'Russia',RW:'Rwanda',SA:'Saudi Arabia',SN:'Senegal',RS:'Serbia',SC:'Seychelles',SG:'Singapore',SK:'Slovakia',SI:'Slovenia',SO:'Somalia',ZA:'South Africa',ES:'Spain',LK:'Sri Lanka',SD:'Sudan',SE:'Sweden',CH:'Switzerland',SY:'Syria',TW:'Taiwan',TJ:'Tajikistan',TZ:'Tanzania',TH:'Thailand',TN:'Tunisia',TR:'Turkey',TM:'Turkmenistan',UA:'Ukraine',AE:'United Arab Emirates',GB:'United Kingdom',US:'United States',UY:'Uruguay',UZ:'Uzbekistan',VE:'Venezuela',VN:'Vietnam',YE:'Yemen',ZM:'Zambia',ZW:'Zimbabwe'};

        function esc(t) { return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        function cleanPoemForCard(raw) {
            if (!raw) return '';
            var s = raw.replace(/\\r\\n/g, '\n').replace(/\r\n/g, '\n');
            var t = s.replace(/^(json|php|html|http|xml|sql|css|plaintext|text)\s*\n/i, '').trim();
            if (/^(GET |HEAD |POST |PUT |DELETE |OPTIONS )/i.test(t)) return '';
            if (/^> (GET|HEAD|POST|PUT) /i.test(t)) return '';
            if (/^\s*[\[{]/.test(t) && /[\]}]\s*$/.test(t)) return '';
            if (/^<\?php/i.test(t)) return '';
            if (/^#\w|^class \w|^function \w|^import |^require |^package /i.test(t)) return '';
            s = s.replace(/^[\s\S]*?(?:A (?:visitor|scanner) just[\s\S]*?\n\n)/i, '');
            s = s.replace(/^Visitor:[\s\S]*?\n\n/i, '');
            s = s.replace(/^Your (work|poem|task|response)[\s\S]*?\n\n/i, '');
            s = s.replace(/^[\s\S]*\n---\n/, '');
            s = s.replace(/<[a-z_-]+>\s*=\s*"[^"]*"/gi, '');
            s = s.replace(/\[[\d.]+\]/g, '');
            s = s.replace(/\*\*Honeypoet'?s?\s+(response|poem):?\*\*:?/gi, '');
            s = s.replace(/^Honeypoet'?s?\s+(response|poem):?\s*\n/gim, '');
            s = s.replace(/\{[\s\S]*?\}/g, function(m) { return /\"(path|method|from|poem)\"/.test(m) ? '' : m; });
            s = s.replace(/https?:\/\/\S+/g, '');
            var pLines = s.split('\n');
            var clean = [];
            for (var pi = 0; pi < pLines.length; pi++) {
                var pl = pLines[pi].replace(/[\u{1F000}-\u{1FFFF}\u{2600}-\u{27BF}\u{FE00}-\u{FE0F}\u{200D}\u{20E3}\u{E0020}-\u{E007F}]/gu, '').trim();
                if (!pl || /^[-<>*#=_`~]+$/.test(pl)) continue;
                if (/^```/.test(pl)) continue;
                var lo = pl.toLowerCase();
                if (/^(use |make |no |write |here is |here's |give |close |end |answer |imagine |let |guidelines|instructions|stay |convey |conclude |maintain |keep |ensure |avoid |include |the (poem|haiku|visit|letter|drawer|log|door)|format |do not |don't |be (sure|philosophical|creative)|your (answer|poem|response)|remember |try |consider |think |in het |auf deutsch|en fran|embrace |and if you|and remember|note:?$|visiting |visitor |visited |knocked |the .+ should|the .+ captures|the .+ lasted|the .+ contains|the .+ reads)/i.test(lo)) continue;
                if (/^- /i.test(pl) && !/[,.]$/.test(pl)) continue;
                if (/^\*\*.+\*\*:?\s*$/.test(pl)) continue;
                if (/^(json|php|html|http|xml|sql|css|plaintext|text)\s*$/i.test(pl)) continue;
                if (/^(GET|HEAD|POST|PUT|DELETE|OPTIONS) \//i.test(pl)) continue;
                if (/^(Host|User-Agent|Accept|Referer|Connection|Upgrade|Content-|Cookie|Authorization|Cache-|Pragma|Origin|DNT|Sec-|X-|Via|From:|If-)[\w-]*:\s*/i.test(pl)) continue;
                if (/^<\?php|^<\/?\w+[\s>\/]|^<!\w|;\s*$|^\$\w|^echo |^session_|^document\.|^function\s*[\(\{]|^location\.|^class \w|^public |^private |^return |^var |^const |^let |^import /i.test(pl)) continue;
                if (/^\/[\w.-]+\.php/i.test(pl)) continue;
                if (/^(from|path|method|ip|host|visitor|request|response)\s*:/i.test(lo)) continue;
                if (/^your (work|poem|task|response)[ :]/i.test(lo)) continue;
                if (/^(the )?honeypoet'?s?\s+(poem|response|says)/i.test(lo)) continue;
                if (/^(i await|no other text|honeypoet,?\s+(begin|your))/i.test(lo)) continue;
                if (/^tagline:|^title:|^poem:/i.test(lo)) continue;
                if (/^(wp |wordpress )/i.test(lo) && pl.length < 30) continue;
                clean.push(pl);
            }
            while (clean.length && clean[0] === '') clean.shift();
            while (clean.length && clean[clean.length - 1] === '') clean.pop();
            s = clean.join('\n');
            s = s.replace(/`/g, '').replace(/\*\*/g, '').replace(/[\u{1F000}-\u{1FFFF}\u{2600}-\u{27BF}\u{FE00}-\u{FE0F}\u{200D}\u{20E3}\u{E0020}-\u{E007F}]/gu, '');
            s = s.replace(/(\d{1,3})\.\d{1,3}\.\d{1,3}\.(\d{1,3})/g, '$1.x.x.$2');
            s = s.replace(/\n{3,}/g, '\n\n');
            s = s.trim();
            var stanzas = s.split('\n\n');
            if (stanzas.length > 3) s = stanzas.slice(0, 3).join('\n\n');
            var lines = s.split('\n');
            if (lines.length > 12) s = lines.slice(0, 12).join('\n');
            if (s.length < 20) return '';
            return s;
        }

        function renderBars(container, items, labelFn, maxCount) {
            var html = '';
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                var pct = Math.round((item.count / maxCount) * 100);
                html += '<div class="stat-row">' +
                    '<span class="stat-label">' + esc(labelFn(item)) + '</span>' +
                    '<div class="stat-bar"><div class="stat-fill" style="width:' + pct + '%"></div></div>' +
                    '<span class="stat-count">' + item.count.toLocaleString() + '</span>' +
                    '</div>';
            }
            document.getElementById(container).innerHTML = html || '<div class="empty">No data yet.</div>';
        }

        function renderExhibits(exhibits, activeWeek) {
            var weeks = Object.keys(exhibits).sort().reverse();
            if (weeks.length === 0) {
                document.getElementById('week-tabs').innerHTML = '';
                document.getElementById('exhibits').innerHTML = '<div class="empty">The first exhibition is being prepared&hellip;</div>';
                return;
            }

            if (!activeWeek || weeks.indexOf(activeWeek) === -1) activeWeek = weeks[0];

            var tabsHtml = '';
            for (var i = 0; i < weeks.length; i++) {
                var cls = weeks[i] === activeWeek ? ' active' : '';
                tabsHtml += '<button class="week-tab' + cls + '" data-week="' + esc(weeks[i]) + '">' + esc(weeks[i]) + '</button>';
            }
            document.getElementById('week-tabs').innerHTML = tabsHtml;

            var poems = exhibits[activeWeek] || [];
            var html = '';
            for (var j = 0; j < poems.length; j++) {
                var p = poems[j];
                var isCode = (p.response_type === 'llm_code');
                var text = isCode ? (p.response_content || '').trim() : cleanPoemForCard(p.response_content);
                if (!text) continue;

                var nameStr = p.visitor_name || 'Someone';
                if (p.visitor_behavior) {
                    nameStr += ', the ' + p.visitor_behavior.charAt(0).toUpperCase() + p.visitor_behavior.slice(1);
                }
                var countryName = p.country ? (CC[p.country] || p.country) : '';
                var cat = p.attack_category || '';
                var archetype = cat === 'visitor' ? 'gallery visit' : (cat ? cat.replace(/_/g, ' ') : '');
                var ts = p.timestamp || '';
                var dateStr = ts ? ts.substring(0, 10) : '';

                var verseStyle = isCode ? ' style="font-family:\'Courier New\',monospace;font-size:12px;line-height:1.5"' : '';
                html += '<div class="exhibit">' +
                    '<div class="attrib">For ' + esc(nameStr) + (countryName ? ' from ' + esc(countryName) : '') + '</div>' +
                    '<div class="meta">' + esc(archetype) + (dateStr ? ' \u00b7 ' + esc(dateStr) : '') + '</div>' +
                    '<div class="verse"' + verseStyle + '>' + esc(text) + '</div>' +
                    '</div>';
            }
            document.getElementById('exhibits').innerHTML = html || '<div class="empty">No poems this week.</div>';

            // Tab click handlers
            var tabs = document.querySelectorAll('.week-tab');
            for (var k = 0; k < tabs.length; k++) {
                tabs[k].onclick = function() { renderExhibits(exhibits, this.getAttribute('data-week')); };
            }
        }

        // Fetch and render
        fetch('/_api/museum')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                // Summary
                var since = data.since ? data.since.substring(0, 10) : '?';
                document.getElementById('summary').innerHTML =
                    '<span class="num">' + (data.total_visitors || 0).toLocaleString() + '</span> visitors' +
                    ' &middot; <span class="num">' + (data.total_poems || 0).toLocaleString() + '</span> poems' +
                    ' &middot; <span class="num">' + (data.countries ? data.countries.length : 0) + '+</span> countries' +
                    '<br>since ' + esc(since);

                // Countries
                var countries = data.countries || [];
                var maxC = countries.length > 0 ? countries[0].count : 1;
                renderBars('countries', countries, function(c) {
                    return (CC[c.country] || c.country) + ' (' + c.country + ')';
                }, maxC);

                // Cities
                var cities = data.cities || [];
                var maxCity = cities.length > 0 ? cities[0].count : 1;
                renderBars('cities', cities, function(c) {
                    return c.city + ', ' + c.country;
                }, maxCity);

                // Exhibits
                renderExhibits(data.exhibits || {});

                // Behavior mapping — museum metaphors
                var behaviorNames = {
                    ghostly: 'Day visitors',
                    curious: 'Browsers',
                    patient: 'Season ticket holders',
                    methodical: 'Researchers',
                    relentless: 'Regulars',
                    hectic: 'Tour groups'
                };
                var behaviorDescs = {
                    ghostly: 'came once, gone',
                    curious: 'wandered between exhibits',
                    patient: 'keep coming back',
                    methodical: 'systematic, thorough',
                    relentless: 'same exhibit, every day',
                    hectic: 'everywhere at once'
                };

                // Category exhibition names
                var catNames = {
                    wordpress: 'The WordPress Gallery',
                    admin_panel: 'The Control Room',
                    env_file: 'The Archive of Secrets',
                    api_probe: 'The Data Hall',
                    generic_scan: 'The Grand Tour',
                    credential_submit: 'The Login Desk',
                    dev_tools: 'The Workshop',
                    webshell: 'The Back Door',
                    upload_exploit: 'The Loading Dock',
                    vcs_leak: 'The Version Vault',
                    cms_fingerprint: 'The Registry',
                    iot_exploit: 'The Machine Room',
                    multi_protocol: 'The Wrong Hallway',
                    config_file: 'The Filing Cabinet',
                    visitor: 'The Lobby'
                };

                // Visitor Profiles
                var behaviors = data.behaviors || [];
                if (behaviors.length > 0) {
                    var maxB = behaviors[0].count;
                    var bhtml = '';
                    for (var bi = 0; bi < behaviors.length; bi++) {
                        var b = behaviors[bi];
                        var bName = behaviorNames[b.behavior] || b.behavior;
                        var bDesc = behaviorDescs[b.behavior] || '';
                        var bPct = Math.round((b.count / maxB) * 100);
                        var label = bName + (bDesc ? ' \u2014 ' + bDesc : '');
                        bhtml += '<div class="stat-row">' +
                            '<span class="stat-label" style="width:310px">' + esc(label) + '</span>' +
                            '<div class="stat-bar"><div class="stat-fill" style="width:' + bPct + '%"></div></div>' +
                            '<span class="stat-count">' + b.count.toLocaleString() + '</span>' +
                            '</div>';
                    }
                    document.getElementById('behaviors').innerHTML = bhtml;
                } else {
                    document.getElementById('behaviors').innerHTML = '<div class="empty">No visitor profiles yet.</div>';
                }

                // Preferred Exhibitions
                var topCats = data.top_categories_by_country || {};
                var tcKeys = Object.keys(topCats);
                if (tcKeys.length > 0) {
                    var peHtml = '';
                    for (var ti = 0; ti < tcKeys.length; ti++) {
                        var tcCountry = tcKeys[ti];
                        var tcList = topCats[tcCountry];
                        var cName = CC[tcCountry] || tcCountry;
                        peHtml += '<div class="country-exhibit">' +
                            '<h3>' + esc(cName) + '</h3><div class="cat-list">';
                        for (var ci = 0; ci < tcList.length; ci++) {
                            var catKey = tcList[ci].category;
                            var exName = catNames[catKey] || catKey.replace(/_/g, ' ');
                            peHtml += (ci > 0 ? '<br>' : '') +
                                esc(exName) + ' \u2014 ' +
                                '<span style="color:rgba(0,0,0,0.4)">' + tcList[ci].count.toLocaleString() + ' visitors</span>';
                        }
                        peHtml += '</div></div>';
                    }
                    document.getElementById('preferred-exhibits').innerHTML = peHtml;
                } else {
                    document.getElementById('preferred-exhibits').innerHTML = '<div class="empty">Not enough data yet.</div>';
                }

                // Average Stay
                var avgData = data.avg_visits_by_country || [];
                if (avgData.length > 0) {
                    var maxAvg = 0;
                    for (var ai = 0; ai < avgData.length; ai++) {
                        if (avgData[ai].avg_visits > maxAvg) maxAvg = avgData[ai].avg_visits;
                    }
                    var avgHtml = '';
                    for (var aj = 0; aj < avgData.length; aj++) {
                        var ac = avgData[aj];
                        var aPct = Math.round((ac.avg_visits / maxAvg) * 100);
                        var aLabel = (CC[ac.country] || ac.country);
                        avgHtml += '<div class="stat-row">' +
                            '<span class="stat-label">' + esc(aLabel) + '</span>' +
                            '<div class="stat-bar"><div class="stat-fill" style="width:' + aPct + '%"></div></div>' +
                            '<span class="stat-count">' + ac.avg_visits + ' avg</span>' +
                            '</div>';
                    }
                    document.getElementById('avg-stay').innerHTML = avgHtml;
                } else {
                    document.getElementById('avg-stay').innerHTML = '<div class="empty">Not enough data yet.</div>';
                }

                // Opening Hours
                var hourly = data.hourly_activity || [];
                if (hourly.length > 0) {
                    var hourMap = {};
                    var maxH = 0;
                    for (var hi = 0; hi < hourly.length; hi++) {
                        hourMap[hourly[hi].hour] = hourly[hi].count;
                        if (hourly[hi].count > maxH) maxH = hourly[hi].count;
                    }
                    var hHtml = '<div class="hourly-grid">';
                    for (var hh = 0; hh < 24; hh++) {
                        var hCount = hourMap[hh] || 0;
                        var hPct = maxH > 0 ? Math.round((hCount / maxH) * 100) : 0;
                        hHtml += '<div class="hourly-col" title="' + hh + ':00 UTC \u2014 ' + hCount.toLocaleString() + ' visits">' +
                            '<div class="hourly-bar" style="height:' + Math.max(hPct, 3) + '%"></div>' +
                            '</div>';
                    }
                    hHtml += '</div><div class="hourly-labels">';
                    for (var hl = 0; hl < 24; hl++) {
                        hHtml += '<span>' + (hl % 3 === 0 ? hl : '') + '</span>';
                    }
                    hHtml += '</div>';
                    document.getElementById('hourly-chart').innerHTML = hHtml;
                } else {
                    document.getElementById('hourly-chart').innerHTML = '<div class="empty">No hourly data yet.</div>';
                }

            })
            .catch(function(e) {
                console.error('Museum fetch error:', e);
            });
    })();
    </script>
</body>
</html>
HTML;
}
