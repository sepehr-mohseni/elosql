# Contributing to Elosql

Thank you for considering contributing to Elosql! We welcome contributions from the community.

## Development Setup

1. Fork and clone the repository:

```bash
git clone https://github.com/sepehr-mohseni/elosql.git
cd elosql
```

2. Install dependencies:

```bash
composer install
```

3. Run tests to ensure everything works:

```bash
composer test
```

## Development Workflow

### Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test-coverage

# Run specific test file
vendor/bin/phpunit tests/Unit/TypeMapperTest.php

# Run specific test method
vendor/bin/phpunit --filter test_maps_mysql_integer_types_correctly
```

### Code Style

We follow PSR-12 coding standards. Use PHP CS Fixer to format your code:

```bash
# Check code style
composer cs-check

# Fix code style
composer format
```

### Static Analysis

We use PHPStan for static analysis at level 8:

```bash
composer analyse
```

## Pull Request Process

1. Create a feature branch from `main`:

```bash
git checkout -b feature/your-feature-name
```

2. Make your changes and ensure:
   - All tests pass (`composer test`)
   - Code style is correct (`composer cs-check`)
   - Static analysis passes (`composer analyse`)
   - New functionality has tests

3. Update documentation if needed:
   - Update README.md for new features
   - Add entries to CHANGELOG.md under [Unreleased]

4. Commit your changes with a clear message:

```bash
git commit -m "Add support for X feature"
```

5. Push to your fork and create a Pull Request

## Guidelines

### Code Standards

- Use strict types in all PHP files: `declare(strict_types=1);`
- Type-hint all method parameters and return types
- Use meaningful variable and method names
- Add PHPDoc blocks for public methods
- Keep methods focused and small

### Testing

- Write tests for new functionality
- Aim for 90%+ code coverage
- Use data providers for testing multiple scenarios
- Test edge cases and error conditions

### Commit Messages

- Use present tense ("Add feature" not "Added feature")
- Use imperative mood ("Move cursor to..." not "Moves cursor to...")
- Reference issues when applicable

### Adding New Database Support

If adding support for a new database:

1. Create a new parser in `src/Parsers/` extending `AbstractSchemaParser`
2. Add the driver to `SchemaParserFactory`
3. Add type mappings in `TypeMapper`
4. Add tests for the new parser
5. Update README.md documentation

### Adding New Column Types

1. Add the mapping in `TypeMapper` for each supported database
2. Update the migration generator if special handling is needed
3. Add cast type mapping for model generation
4. Add tests for the new type

## Questions?

If you have questions about contributing, feel free to:

- Open an issue for discussion
- Reach out on GitHub Discussions

Thank you for contributing!
