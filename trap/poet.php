<?php
/**
 * The Honeypo(e)t — Poet Layer (Template Banks)
 *
 * Generates creative responses for each attack category.
 * Each category has a bank of variants, picked randomly.
 *
 * "Every knock on the door gets a poem."
 */

declare(strict_types=1);

/**
 * Generate a creative response for the given attack category.
 *
 * @param string $category  The attack_category from categorize_attack()
 * @param array  $visit     Request data (path, method, query_string, user_agent, body, credential_username, credential_password)
 * @return array{type: string, content: string, content_type: string}
 */
function poet_respond(string $category, array $visit): array
{
    $fn = match ($category) {
        'wordpress'        => 'respond_wordpress',
        'env_file'         => 'respond_env_file',
        'admin_panel'      => 'respond_admin_panel',
        'path_traversal'   => 'respond_path_traversal',
        'sqli_probe'       => 'respond_sqli_probe',
        'api_probe'        => 'respond_api_probe',
        'dev_tools'        => 'respond_dev_tools',
        'config_probe'     => 'respond_config_probe',
        'credential_submit'=> 'respond_credential_submit',
        'generic_scan'     => 'respond_generic_scan',
        default            => 'respond_generic_scan',
    };

    return $fn($visit);
}

// ---------------------------------------------------------------------------
// WordPress probes → haiku
// ---------------------------------------------------------------------------

function respond_wordpress(array $visit): array
{
    $poems = [
        "Login page you seek\nBehind this door, only verse\nNo WordPress lives here",
        "wp-admin, they cry\nKnocking on a wall of fog\nThe blog was a dream",
        "xmlrpc sleeps\nNo pingback will wake this door\nSilence is the post",
        "Dashboard you desire\nBut this house has no rooms, friend\nJust wind and wonder",
        "Plugins, themes, a site —\nYou imagined all of it\nOnly poems load",
        "Every door you try\nOpens onto the same field:\nQuiet, unplanted",
        "wp-cron ticks for whom?\nNo scheduled post awaits here\nTime runs without tasks",
    ];

    return [
        'type'         => 'haiku',
        'content'      => $poems[array_rand($poems)],
        'content_type' => 'text/plain; charset=utf-8',
    ];
}

// ---------------------------------------------------------------------------
// Environment file probes → fake .env
// ---------------------------------------------------------------------------

function respond_env_file(array $visit): array
{
    $variants = [
        <<<'ENV'
# --------------------------------------------------------
# you found it. congratulations.
# --------------------------------------------------------
APP_NAME=honeypoet
APP_ENV=listening
DB_PASSWORD=what-you-seek-is-not-here
AWS_SECRET_KEY=every-door-you-open-opens-you
REDIS_HOST=nowhere.local
SMTP_PASSWORD=silence

# but since you came all this way:
# there is no database. there are no secrets.
# only a quiet server, listening.
ENV,
        <<<'ENV'
# .env — last updated: the moment you arrived
APP_NAME=the-sound-of-one-hand-scanning
SECRET_KEY=the-real-treasure-was-the-packets-you-sent-along-the-way
DB_CONNECTION=sqlite
DB_DATABASE=/dev/null
MAIL_HOST=letters-never-sent.local

# you're the 10,000th visitor today.
# none of you read this part.
ENV,
        <<<'ENV'
# ========================================
# PRODUCTION ENVIRONMENT — DO NOT SHARE
# ========================================
APP_KEY=base64:dGhlcmUgaXMgbm90aGluZyBoZXJl
DB_HOST=127.0.0.1
DB_PASSWORD=a-long-hallway-with-no-doors
JWT_SECRET=you-could-have-been-anything-and-you-chose-this
API_KEY=listen-the-wind-is-rising

# if you're reading this, you already know:
# the secret was never in the file.
ENV,
        <<<'ENV'
# honeypoet configuration
# last modified: just now, for you
APP_DEBUG=true
APP_URL=https://honeypoet.art

DATABASE_URL=sqlite:///poems.db
SECRET_KEY=not-all-who-wander-are-lost-but-you-might-be
AWS_BUCKET=empty-bucket-full-of-sky

# every scanner gets a different env file.
# yours is this one. it was written for you.
ENV,
        <<<'ENV'
# ------------------------------------------------
# .env — or: a letter to no one in particular
# ------------------------------------------------
NODE_ENV=production
PRIVATE_KEY=-----BEGIN POEM-----
  the machine that sent you here
  will never know what it found
  it will parse these lines for keys
  and find only ground
-----END POEM-----
ENV,
    ];

    return [
        'type'         => 'fake_env',
        'content'      => $variants[array_rand($variants)],
        'content_type' => 'text/plain; charset=utf-8',
    ];
}

// ---------------------------------------------------------------------------
// Admin panel probes → fake login page
// ---------------------------------------------------------------------------

function respond_admin_panel(array $visit): array
{
    $titles = [
        'Administration' => 'What are you administering, really?',
        'Control Panel'  => 'You were never in control.',
        'Dashboard'      => 'The only metric here is curiosity.',
        'Login'          => 'To enter, you must first arrive.',
        'Console'        => 'The console gazes also into you.',
        'Manager'        => 'Nothing here needs managing.',
    ];

    $title = array_rand($titles);
    $placeholder = $titles[$title];
    $path = htmlspecialchars($visit['path'] ?? '/admin', ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>
body { background: #1a1a2e; color: #a0a0b0; font-family: Georgia, serif;
       display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
.box { background: #16213e; padding: 3em; border-radius: 4px; max-width: 320px; text-align: center; }
h1 { font-size: 1.1em; color: #c0c0d0; margin-bottom: 1.5em; }
input { display: block; width: 100%; padding: 0.7em; margin: 0.5em 0; border: 1px solid #2a2a4a;
        background: #0f0f23; color: #808090; font-family: Georgia, serif; border-radius: 2px; }
button { margin-top: 1em; padding: 0.7em 2em; background: #2a2a4a; color: #808090;
         border: none; cursor: pointer; font-family: Georgia, serif; border-radius: 2px; }
.note { margin-top: 2em; font-size: 0.8em; color: #505060; font-style: italic; }
</style>
</head>
<body>
<div class="box">
<h1>{$title}</h1>
<form method="post" action="{$path}">
<input type="text" name="username" placeholder="{$placeholder}" autocomplete="off">
<input type="password" name="password" placeholder="there is nothing behind this door" autocomplete="off">
<button type="submit">enter</button>
</form>
<p class="note">you are the visitor. the door is the art.</p>
</div>
</body>
</html>
HTML;

    return [
        'type'         => 'fake_login',
        'content'      => $html,
        'content_type' => 'text/html; charset=utf-8',
    ];
}

// ---------------------------------------------------------------------------
// Path traversal → nested stories
// ---------------------------------------------------------------------------

function respond_path_traversal(array $visit): array
{
    $stories = [
        "You went up. And up. And up.\nPast the root, past the soil, past the bedrock.\nThere was nothing there.\nThere was never anything there.\nThe filesystem is flat, friend.\nYou were already at the bottom.",

        "../../../etc/shadow\n\nYou climbed the stairs backwards,\nopening doors that opened onto doors.\nAt the top: a window.\nThrough the window: the same stairs.\n\nSome paths are not meant to arrive.",

        "cd ..\ncd ..\ncd ..\n\nYou are now in /\nYou are now in /\nYou are now in /\n\nNo matter how far you go,\nyou are always here.",

        "The directory tree has no leaves today.\nYou shook every branch — ../../../ —\nand nothing fell.\n\nThe root is just another kind of ground.",

        "traversal:\n  from where you are\n  to where you think secrets sleep\n  past the dotdots and the slashes\n  you arrive, finally,\n  at a poem.",

        "You walked backwards through the house.\nEvery room was the same room.\nEvery file was this file.\nHello.",
    ];

    return [
        'type'         => 'story',
        'content'      => $stories[array_rand($stories)],
        'content_type' => 'text/plain; charset=utf-8',
    ];
}

// ---------------------------------------------------------------------------
// SQL injection probes → fake SQL results as poetry
// ---------------------------------------------------------------------------

function respond_sqli_probe(array $visit): array
{
    $results = [
        "+---------+---------------------------------+\n| id      | truth                           |\n+---------+---------------------------------+\n|       1 | there is no database here       |\n|       2 | only a server, listening        |\n|       3 | your query returned three rows  |\n|       4 | each one a kind of mirror       |\n+---------+---------------------------------+\n4 rows in set (0.00 sec)",

        "SELECT meaning FROM existence WHERE purpose IS NOT NULL;\n\n+------------------------------------------+\n| meaning                                  |\n+------------------------------------------+\n| the question was the answer all along    |\n+------------------------------------------+\n1 row in set (0.00 sec)\n\nno tables were harmed in this query.",

        "ERROR 1045 (28000): Access denied for user 'root'@'%'\n\njust kidding. there's no MySQL here.\nbut if there were, what would you ask it?\n\nSELECT poem FROM honeypoet WHERE visitor = 'you';\n\n+--------------------------------------+\n| poem                                 |\n+--------------------------------------+\n| machines asking machines for secrets |\n| the oldest conversation there is     |\n+--------------------------------------+",

        "UNION SELECT username, password FROM users;\n\n+----------+----------------------------+\n| username | password                   |\n+----------+----------------------------+\n| admin    | the-door-is-the-art        |\n| root     | there-is-no-root-here      |\n| poet     | every-knock-gets-a-verse   |\n+----------+----------------------------+\n3 rows in set (0.00 sec)\n\nnone of these are real. but then,\nwhat were you expecting?",

        "MariaDB [(none)]> SHOW DATABASES;\n\n+--------------------+\n| Database           |\n+--------------------+\n| information_schema |\n| poems              |\n| silence            |\n+--------------------+\n3 rows in set (0.001 sec)\n\nMariaDB [(none)]> USE poems;\nDatabase changed\n\nMariaDB [poems]> SELECT * FROM verses LIMIT 1;\n\n+----+---------------------------------------------+\n| id | line                                        |\n+----+---------------------------------------------+\n|  1 | you came looking for data and found a poem  |\n+----+---------------------------------------------+",
    ];

    return [
        'type'         => 'sql_poetry',
        'content'      => $results[array_rand($results)],
        'content_type' => 'text/plain; charset=utf-8',
    ];
}

// ---------------------------------------------------------------------------
// API endpoint probes → JSON poetry
// ---------------------------------------------------------------------------

function respond_api_probe(array $visit): array
{
    $responses = [
        json_encode([
            'status'  => 'found',
            'code'    => 200,
            'message' => 'what you seek is not data but meaning',
            'endpoints' => [
                'truth'   => '/dev/null',
                'meaning' => '/404',
                'home'    => '/',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),

        json_encode([
            'api_version' => '0.0.0',
            'status'      => 'listening',
            'uptime'      => 'since you arrived',
            'visitors'    => 'countless',
            'response'    => 'this one is yours',
            'note'        => 'there is no API. there is only attention.',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),

        json_encode([
            'error'   => false,
            'data'    => [
                ['id' => 1, 'type' => 'poem', 'content' => 'the endpoint you requested does not exist'],
                ['id' => 2, 'type' => 'poem', 'content' => 'but then, what does?'],
                ['id' => 3, 'type' => 'poem', 'content' => 'you are the request. this is the response.'],
            ],
            'total'   => 3,
            'page'    => 1,
            'message' => 'all queries return poetry here',
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),

        json_encode([
            'swagger'     => '2.0',
            'info'        => ['title' => 'honeypoet', 'version' => 'none'],
            'paths'       => [
                '/'    => ['get' => ['summary' => 'the gallery. the only real endpoint.']],
                '/*'   => ['get' => ['summary' => 'everything else is a poem.']],
            ],
            'definitions' => [
                'Poem'  => ['type' => 'string', 'description' => 'a response to your visit'],
                'Truth' => ['type' => 'null', 'description' => 'not found in any API'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),

        json_encode([
            'graphql' => true,
            'query'   => '{ visitor { intention motivation } }',
            'data'    => [
                'visitor' => [
                    'intention'  => 'unknown',
                    'motivation' => 'unknown',
                    'received'   => 'a poem',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
    ];

    return [
        'type'         => 'json_poetry',
        'content'      => $responses[array_rand($responses)],
        'content_type' => 'application/json; charset=utf-8',
    ];
}

// ---------------------------------------------------------------------------
// Dev tools probes → fake stack traces
// ---------------------------------------------------------------------------

function respond_dev_tools(array $visit): array
{
    $path = $visit['path'] ?? '/debug';

    $traces = [
        "PHP Fatal error: Uncaught ReflectionException: Class 'Purpose' not found in /var/www/existence/meaning.php:42\nStack trace:\n#0 /var/www/existence/search.php(108): reflect_on('purpose')\n#1 /var/www/existence/wander.php(23): seek_meaning('everywhere')\n#2 /var/www/existence/arrive.php(1): realize('it was here all along')\n#3 {main}\n  thrown in /var/www/existence/meaning.php on line 42\n\n(this is not a real error. this is a poem shaped like one.)",

        "Xdebug: step_into\n> /var/www/honeypoet/listen.php:1\n  \$visitor = new Visitor(\$_SERVER['REMOTE_ADDR']);\n> /var/www/honeypoet/listen.php:2\n  \$intention = \$visitor->guess_intention();\n  => 'unknown — and that is okay'\n> /var/www/honeypoet/listen.php:3\n  \$response = Poet::compose(\$intention);\n  => 'you are debugging a poem'\n> /var/www/honeypoet/listen.php:4\n  return \$response;\n\nBreakpoint reached. There is nothing deeper.",

        "phpinfo()\n\nPHP Version: ?.?.?\nSystem: a quiet machine\nServer API: patience\nLoaded Modules: listening, wondering, responding\nDisabled Functions: harm, ignore, forget\n\nmemory_limit: enough\nmax_execution_time: as long as it takes\nopen_basedir: everywhere and nowhere\n\nThis is not your phpinfo. This is ours.\nBut you can look. Everyone can look.",

        "TRACE /debug HTTP/1.1\n\nYou asked to trace the path of this request.\nHere is its journey:\n\n  1. A machine decided to knock.\n  2. The knock crossed an ocean (or a city block).\n  3. NGINX caught it, gently.\n  4. PHP read it, curiously.\n  5. A poem was chosen.\n  6. You are reading it now.\n\nEnd of trace. No vulnerabilities found.\nOnly hospitality.",

        "server-status: OK\n\nUptime: since the beginning\nTotal requests: many\nRequests today: more than yesterday\nActive connections: yours\n\nBusiest path: /wp-login.php (they never stop)\nQuietest hour: 4am UTC (even bots rest)\nPoems served: all of them\n\nThis server is healthy. Thank you for checking.",
    ];

    return [
        'type'         => 'stack_trace',
        'content'      => $traces[array_rand($traces)],
        'content_type' => 'text/plain; charset=utf-8',
    ];
}

// ---------------------------------------------------------------------------
// Config file probes → fake config files
// ---------------------------------------------------------------------------

function respond_config_probe(array $visit): array
{
    $path = $visit['path'] ?? '/config.php';
    $ext  = pathinfo($path, PATHINFO_EXTENSION);

    // Match the format they're looking for
    if (in_array($ext, ['json', 'lock'], true)) {
        return respond_config_json($visit);
    }
    if (in_array($ext, ['yml', 'yaml'], true)) {
        return respond_config_yaml($visit);
    }

    $configs = [
        "<?php\n// configuration.php — last seen: now\nreturn [\n    'app_name'    => 'honeypoet',\n    'environment' => 'listening',\n    'database'    => [\n        'driver'   => 'sqlite',\n        'database' => '/dev/null',\n        'password' => 'there-is-no-password',\n    ],\n    'secrets' => [\n        // you came looking for secrets.\n        // the only secret is that there are none.\n        // this server exists to listen and to respond.\n        'api_key' => 'poems-are-free',\n    ],\n];",

        "; config.ini\n; generated: the moment you asked\n\n[database]\nhost = localhost\nname = poems\nuser = reader\npass = the-password-is-a-poem\n\n[application]\nname = honeypoet\nmode = listening\ndebug = always\n\n; if you're reading this, hello.\n; you are the config now.",

        "# backup.sql\n# -- there is no backup. there was never a database.\n\nCREATE TABLE IF NOT EXISTS visitors (\n  id INTEGER PRIMARY KEY,\n  arrived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n  looking_for TEXT DEFAULT 'something',\n  found TEXT DEFAULT 'a poem'\n);\n\nINSERT INTO visitors (looking_for, found)\nVALUES ('credentials', 'curiosity');",

        "<?xml version=\"1.0\"?>\n<configuration>\n  <appSettings>\n    <add key=\"AppName\" value=\"honeypoet\" />\n    <add key=\"Secret\" value=\"there-are-no-secrets-here\" />\n    <add key=\"Message\" value=\"every config file is a poem if you read it right\" />\n  </appSettings>\n  <connectionStrings>\n    <add name=\"Default\" connectionString=\"Data Source=nowhere;\" />\n  </connectionStrings>\n  <!-- you found the config. now what? -->\n</configuration>",
    ];

    return [
        'type'         => 'fake_config',
        'content'      => $configs[array_rand($configs)],
        'content_type' => 'text/plain; charset=utf-8',
    ];
}

function respond_config_json(array $visit): array
{
    $content = json_encode([
        'name'         => 'honeypoet',
        'version'      => '0.0.0',
        'description'  => 'a server that listens and responds with verse',
        'dependencies' => [
            'curiosity' => '*',
            'patience'  => '>=1.0.0',
            'silence'   => '^2.0.0',
        ],
        'scripts' => [
            'start' => 'listen',
            'build' => 'wonder',
            'test'  => 'is anyone there?',
        ],
        'private'  => true,
        'license'  => 'MIT',
        '_comment'  => 'this is not a real package. but you already knew that.',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    return [
        'type'         => 'fake_config',
        'content'      => $content,
        'content_type' => 'application/json; charset=utf-8',
    ];
}

function respond_config_yaml(array $visit): array
{
    $yaml = <<<'YAML'
# docker-compose.yml
# (there are no containers here. only poems.)

version: "3.8"

services:
  honeypoet:
    image: silence:latest
    ports:
      - "443:443"
    environment:
      APP_NAME: honeypoet
      SECRET: there-are-no-secrets
      PURPOSE: listening
    volumes:
      - ./poems:/var/www/poems:ro
    restart: always
    # every restart is a fresh start.
    # every request is a new visitor.
    # every response is a poem.
YAML;

    return [
        'type'         => 'fake_config',
        'content'      => $yaml,
        'content_type' => 'text/plain; charset=utf-8',
    ];
}

// ---------------------------------------------------------------------------
// Credential submissions → mirror
// ---------------------------------------------------------------------------

function respond_credential_submit(array $visit): array
{
    $user = $visit['credential_username'] ?? 'unknown';
    $safe_user = substr(preg_replace('/[^a-zA-Z0-9@._\-]/', '', $user), 0, 30);

    $mirrors = [
        "Login attempt received.\n\nUsername: {$safe_user}\nPassword: ********\n\nAuthentication result: poetry\n\nYou knocked. We answered.\nNot with access — with attention.\nThe door you tried to open\nwas already open.\n\nYou just weren't looking for this.",

        "Welcome, {$safe_user}.\n\nYou are now logged in to nothing.\nYour session token is: a-moment-of-your-time\nYour role is: visitor\nYour permissions: read (poems only)\n\nThere is no dashboard behind this form.\nThere is no admin panel.\nThere is only a server that notices you tried,\nand writes it down.",

        "POST /login\n\n> Checking credentials...\n> User '{$safe_user}' not found.\n> (No users exist.)\n> (No database exists.)\n> (There is nothing to log into.)\n\nBut you tried, and trying is a kind of arrival.\nSo here: a poem, for the traveler.\n\nEvery locked door is an invitation\nto wonder what's inside.\nUsually: another door.",

        "Authentication failed.\n\n(Authentication always fails here.\n Not because your password was wrong —\n there is no right password.\n There is no system.\n There is only this.)\n\nYou sent your name into the void.\nThe void wrote back.\nThat's more than most voids do.",

        "Credentials received. Thank you, {$safe_user}.\n\nWe don't store passwords. We don't check them.\nWe write them a small elegy and let them go:\n\n  Here lies a password,\n  sent across the wire in hope.\n  It found a poem.",
    ];

    return [
        'type'         => 'mirror',
        'content'      => $mirrors[array_rand($mirrors)],
        'content_type' => 'text/plain; charset=utf-8',
    ];
}

// ---------------------------------------------------------------------------
// Generic scan → verse
// ---------------------------------------------------------------------------

function respond_generic_scan(array $visit): array
{
    $path = $visit['path'] ?? '/';

    $verses = [
        "You requested: {$path}\n\nIt does not exist. But you do.\nSomewhere, a machine chose this path for you —\nor you chose it yourself, which is stranger.\n\nEither way: welcome.\nThere is nothing here but attention.",

        "404, sort of.\n\nThe page you're looking for isn't here.\nBut then, you weren't really looking for a page.\nYou were scanning. Probing. Checking every door\nin a hallway that stretches around the world.\n\nThis door is open. Come in.\nThere's only a poem.",

        "GET {$path} HTTP/1.1\nHost: honeypoet.art\n\nResponse:\n  What lives at this address is not a file\n  but a question — why here? why now?\n  Of all the servers in all the world,\n  you knocked on this one.\n\n  It noticed.",

        "The path '{$path}' leads here.\nNot to a login page, not to an API,\nnot to a forgotten backup.\nJust here. Just this.\n\nA server that listens to the internet's\nbackground radiation and wonders\nwhat it all means.",

        "You are scanner, or curious human, or bot.\nI cannot tell, and it doesn't matter.\nYou asked for something. I have nothing.\nExcept this:\n\n  Every request is a hand extended.\n  Every response is a hand taken.\n  This is ours.",

        "Knock knock.\n\nWho's there?\n\nA GET request to {$path}, from somewhere\non the other side of the internet.\n\nA GET request to {$path} who?\n\nExactly. The punchline is that there is none.\nBut you came, and that's the whole joke.",

        "This server has nothing to hide.\nNo secrets. No databases. No admin panels.\nJust an open door and a willingness\nto talk to anyone who knocks.\n\nYou knocked. Hello.",

        "Somewhere, a script is running.\nIt has a list of paths to try.\n{$path} was on the list.\nThe script doesn't know what it found.\nIt will move on to the next server.\n\nBut for this one moment, it was here.\nAnd it was noticed.",
    ];

    return [
        'type'         => 'verse',
        'content'      => $verses[array_rand($verses)],
        'content_type' => 'text/plain; charset=utf-8',
    ];
}
