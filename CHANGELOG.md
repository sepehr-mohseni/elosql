# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-01-06

### Added

- Initial release of Elosql
- Schema parsing support for MySQL, PostgreSQL, SQLite, and SQL Server
- Migration generation with proper dependency ordering
- Foreign key handling in separate migration files
- Eloquent model generation with relationships
- Support for all common column types and their Laravel equivalents
- Automatic relationship detection:
  - `belongsTo` from foreign keys
  - `hasMany` and `hasOne` from reverse foreign keys
  - `belongsToMany` from pivot tables
  - `morphTo` and `morphMany` from polymorphic columns
- Schema comparison and diff generation
- Artisan commands:
  - `elosql:schema` - Generate both migrations and models
  - `elosql:migrations` - Generate migrations only
  - `elosql:models` - Generate models only
  - `elosql:preview` - Preview schema without generating files
  - `elosql:diff` - Show differences between database and migrations
- Configurable options:
  - Table inclusion/exclusion filters
  - Custom type mappings
  - Model namespace configuration
  - Timestamp column detection
  - Pivot table detection patterns
- PHPDoc annotations in generated code
- PSR-12 compliant code generation
- Comprehensive test suite with 90%+ coverage

### Security

- No direct SQL injection vulnerabilities
- All database queries use parameter binding

[Unreleased]: https://github.com/sepehr-mohseni/elosql/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/sepehr-mohseni/elosql/releases/tag/v1.0.0
