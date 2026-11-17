param([Parameter(Mandatory=$true)][ValidateRange(1,2147483647)][int]$PullRequest)
$ErrorActionPreference = 'Stop'
$repo = 'caymanlogistic-crypto/1c-ip-php'
if (git status --porcelain) { throw 'Commit your candidate before enqueueing it.' }
$candidateSha = (git rev-parse HEAD).Trim()
$pr = gh pr view $PullRequest --repo $repo --json headRefOid,baseRefName,isDraft,state | ConvertFrom-Json
if ($LASTEXITCODE -ne 0) { throw 'Cannot read PR.' }
if ($pr.state -ne 'OPEN' -or $pr.isDraft -or $pr.baseRefName -ne 'main') { throw 'PR must be open, ready and target main.' }
if ($pr.headRefOid -ne $candidateSha) { throw 'Push this exact candidate to its PR branch first.' }
gh workflow run merge-queue.yml --repo $repo --ref main -f "pr=$PullRequest" -f "head=$candidateSha"
if ($LASTEXITCODE -ne 0) { throw 'Queue request failed.' }
Write-Host "Queue request submitted for PR #$PullRequest ($candidateSha). Status will appear on the PR; you can continue local work."
