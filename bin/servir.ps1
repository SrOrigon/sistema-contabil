$php = Join-Path $PSScriptRoot "..\.tools\php\php.exe"
if (-not (Test-Path $php)) { $php = "php" }
& $php (Join-Path $PSScriptRoot "console") servir
