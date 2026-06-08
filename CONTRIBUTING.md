# Contributing to BulkWPSender

Thanks for your interest! Contributions are welcome.

## Ground rules
- This project is for **legitimate, opted-in** messaging only. PRs that add
  spam-enabling features (mass scraping, contact harvesting, evasion of WhatsApp
  safety limits beyond reasonable humanization) will not be accepted.
- Be respectful and constructive in issues and reviews.

## Project layout
- `engine/` — Node.js service (whatsapp-web.js, Cloud API, REST API, sender loop)
- `public/` — PHP control panel (calls the engine over HTTP)
- `installer/`, `setup.ps1`, `tray.ps1`, `*.bat`, `*.vbs` — Windows packaging
- `docs/ROADMAP.md` — known gaps and planned features

## Dev setup
```bash
cd engine && npm install && npm start    # http://localhost:3000
php -S localhost:8080 -t public          # http://localhost:8080
```

## Before opening a PR
- Keep changes focused; describe the user-facing effect.
- `node --check` your JS and `php -l` your PHP files (no syntax errors).
- Don't commit secrets or the `data/`, `runtime/`, `node_modules/` folders.
- Update `README.md` / `docs/ROADMAP.md` if behavior or scope changes.

## Reporting bugs
Open an issue with steps to reproduce, expected vs actual behavior, and your
OS / Node / PHP versions. For security issues, email **atul7275@gmail.com**
instead of filing a public issue.
