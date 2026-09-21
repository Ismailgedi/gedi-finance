Gedi Finance Force Password Change Fix v4

The v3 installer partially applied changes and then failed while locating the auth shell.
This v4 installer continues from the current project state. Do not rerun v3.

It:
- ensures must_change_password is fillable/cast on User
- ensures admin create and reset set must_change_password=true
- adds must_change_password to the frontend AuthUser type
- adds a forced password-change screen
- gates the authenticated app when the flag is true
- keeps the existing password update endpoint, which clears the flag
- creates timestamped backups for changed files
- validates PHP and runs the frontend build

Run from:
C:\Users\igedi\gedi-finance

powershell -ExecutionPolicy Bypass -File .\gedi-force-password-change-v4\apply-force-password-change-fix.ps1
