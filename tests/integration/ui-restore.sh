#!/usr/bin/env bash
# Browser test (playwright-cli): upload a >2 MB backup on a site with upload_max_filesize=2M, then restore it.
set -euo pipefail
F=$(cd "$(dirname "$0")/../fixtures/large" && pwd)/full-encrypted-gzip.wpress
U=http://localhost:8092
playwright-cli open "$U/wp-login.php" >/dev/null
playwright-cli fill "#user_login" admin >/dev/null
playwright-cli fill "#user_pass" admin --submit >/dev/null
playwright-cli goto "$U/wp-admin/admin.php?page=pro-backups" >/dev/null
playwright-cli run-code "async page => {
  const msg = () => page.locator('.pbk-modal-message');
  await page.setInputFiles('#pbk-file', '$F');
  await page.waitForFunction(() => /password/i.test(document.querySelector('.pbk-modal-message').textContent), null, { timeout: 120000 });
  await page.fill('.pbk-modal-form input', 'secret123');
  await page.click('.pbk-modal-actions .button-primary');
  await page.waitForFunction(() => /Continue\\?/.test(document.querySelector('.pbk-modal-message').textContent), null, { timeout: 60000 });
  await page.click('.pbk-modal-actions .button-primary');
  await page.waitForFunction(() => /Restore complete|Error/.test(document.querySelector('.pbk-modal-message').textContent), null, { timeout: 300000 });
}" >/dev/null
result=$(playwright-cli --raw eval "document.querySelector('.pbk-modal-message').textContent")
playwright-cli close >/dev/null
echo "$result"
echo "$result" | grep -q "Restore complete" && echo "UI RESTORE PASSED"
