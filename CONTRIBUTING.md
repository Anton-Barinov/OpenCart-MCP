# Contributing to OpenCart-MCP

Thank you for your interest in contributing to **OpenCart-MCP**!

## Development Guidelines
1. **PHP Compatibility**: All core files must run cleanly on PHP 5.4 through PHP 8.4+ without fatal errors, syntax incompatibilities, or deprecation warnings.
2. **OpenCart Compatibility**: Features should support OpenCart 2.x, 3.x, and 4.x schemas transparently.
3. **Security**: All SQL statements must be parameterized; no raw user inputs should ever be directly interpolated into SQL strings.
4. **Testing**: Run `php tests/run_tests.php` before submitting PRs.

## Submitting Pull Requests
- Fork the repository.
- Create a feature branch: `git checkout -b feature/my-feature`.
- Commit changes with descriptive commit messages.
- Open a Pull Request against `main`.
