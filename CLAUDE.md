# HR Management System

- `frontend/` — Next.js + TypeScript
- `backend/` — Laravel API
- Database — MySQL (`hr_management`)
- `docs/` — planning documents (git-ignored)

## Hard rules (set by the project owner)

1. **Never commit** on your own. Commit only when the user explicitly asks for it.
2. **Never run database migrations** (`php artisan migrate`, `migrate:rollback`, `migrate:refresh`, `migrate:reset`, ...). Write the migration files and give the user the command to run.
3. **Never, ever run `php artisan migrate:fresh`** (or `db:wipe`).
4. **Never switch git branches** on your own.
5. **Never create git branches** on your own.

Approval for one of these actions applies only to that one time; it does not carry over to the next.
