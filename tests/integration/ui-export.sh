#!/usr/bin/env bash
# Browser test (playwright-cli): "Create backup" tab on src, then download the result from the dialog.
set -euo pipefail
U=http://localhost:8091
playwright-cli open "$U/wp-login.php" >/dev/null
playwright-cli fill "#user_login" admin >/dev/null
playwright-cli fill "#user_pass" admin --submit >/dev/null
playwright-cli goto "$U/wp-admin/admin.php?page=pro-backups&tab=create" >/dev/null
out=$(playwright-cli run-code "async page => {
  await page.check('input[value=media]');
  await page.selectOption('#pbk-compression', 'gzip');
  await page.click('#submit');
  await page.waitForFunction(() => /Backup created|Error/.test(document.querySelector('.pbk-modal-message').textContent), null, { timeout: 300000 });
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('.pbk-modal-actions a')]);
  return dl.suggestedFilename();
}")
playwright-cli close >/dev/null
echo "$out" | grep -q '\.wpress' && echo "UI EXPORT PASSED" || { echo "$out"; exit 1; }
