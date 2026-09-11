# Contributing to Paragrafy

Thanks for your interest in improving Paragrafy — a self-hosted PHP + SQLite backend for managing legal texts across multiple projects. Contributions of all sizes are welcome, from typo fixes to new features.

## Reporting Bugs

Before opening an issue, please search existing issues to avoid duplicates. A good bug report includes:

- Paragrafy version (or commit hash) and deployment method (Docker / Apache bare metal)
- PHP version and relevant extensions (`sodium`, `gd`, `pdo`)
- Steps to reproduce
- Expected vs. actual behavior
- Relevant logs (`ErrorLog`/`CustomLog` for Apache, `docker compose logs` for Docker), with any secrets redacted

**Please do not include sensitive data** (admin credentials, cron secrets, real user emails) in issues. If you've found a security vulnerability, do not open a public issue — see [SECURITY.md](SECURITY.md) instead.

## Suggesting Features

Open an issue describing the problem you're trying to solve, not just the solution — this makes it easier to discuss alternatives. For anything beyond a small fix, please open an issue first to discuss the approach before investing time in a pull request.

## Local Development Setup

Paragrafy is a plain PHP application backed by SQLite, with optional TOTP two-factor auth via Composer dependencies.

**Requirements:**
- PHP 8.2+
- `ext-sodium`, `ext-pdo` (bundled with a stock PHP 8.2 install)
- `ext-gd` (for TOTP QR codes; `apt install php8.2-gd` on some distros)
- Composer
- Docker & Docker Compose (optional, but the easiest way to get a consistent environment)

**Option A — Docker (recommended):**

```bash
git clone https://github.com/mineco-de/Paragrafy.git
cd Paragrafy
docker compose up -d --build
```

Then open `http://localhost:5555/install.php` in your browser to run the setup wizard. Code changes on the host are reflected after a rebuild (`docker compose up -d --build`), since the image is built from your local checkout.

**Option B — PHP's built-in server:**

```bash
composer install
php -S localhost:8000
```

Then open `http://localhost:8000/install.php` to run the setup wizard. This creates `config.php` and `paragrafy_data.sqlite` in the project root.

See [README.md](README.md) for full configuration details, environment variables, and cron jobs.

## Making Changes

1. Fork the repository and create a branch off `main` (e.g. `fix/cookie-banner-locale`, `feature/webhook-retry-limit`).
2. Keep changes focused — a pull request should do one thing. Unrelated cleanup makes review harder.
3. Test your change manually against the app (installer, admin dashboard, public viewer, or API, depending on what you touched) before opening a PR.
4. If you touch translations, run `php bin/check-translations.php` to make sure `lang/*.php` key sets stay in sync.

## Code Style

- Match the existing style of the file you're editing — Paragrafy has no formal style guide, but stays consistent within each file.
- Prefer small, readable functions over clever one-liners.
- Avoid introducing new dependencies unless there's a strong reason.

## Commit Messages

Please use [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <short description>
```

Allowed types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`.

Examples:

```
fix(cookie-banner): use per-locale text instead of German fallback
feat(webhooks): add configurable retry limit
docs(readme): clarify Docker volume setup
```

Commit messages and pull request descriptions should be written in English.

## Submitting a Pull Request

1. Push your branch and open a pull request against `main`.
2. Describe what changed and why — link any related issue.
3. Be responsive to review feedback; small follow-up commits are fine.

By contributing, you agree that your contributions will be licensed under the project's [AGPL-3.0](LICENSE) license.
