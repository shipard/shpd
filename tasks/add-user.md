# Task: CLI příkaz `shpd-ds user-create`

**Stav:** hotovo

```
Create a new CLI command `user-create` for the `shpd-ds` tool that creates a user in the data source's `core_system_users` table.

Read these files for context and coding conventions:
- src/Command/DataSource/DsUpgradeCommand.php (pattern for DS commands)
- src/Command/DataSource/HelpCommand.php (simple command example)
- src/Core/Database/DataSourceConnection.php (DB operations)
- src/Api/Controller/AuthController.php (password hashing, user lookup)
- modules/core/system/tables/core_system_users.jsonc (table schema)
- bin/shpd-ds (command registration)

## Requirements

Create `src/Command/DataSource/UserCreateCommand.php`:

- Command name: `user-create`
- Description: "Create a new user in the data source"
- Required options: `--login`, `--password`, `--name` (full name)
- Optional option: `--email`

### Behavior

1. Verify CWD is a data source directory (config/main.json must exist), exit with error if not
2. Create DataSourceConfig from CWD, then DataSourceConnection
3. Check if user with given login already exists — if so, show error and exit
4. Hash the password using `password_hash()` with `PASSWORD_DEFAULT` (bcrypt) — same as AuthController uses for verification via `password_verify()`
5. Insert into `core_system_users`:
   - login: from --login
   - password_hash: bcrypt hash
   - full_name: from --name
   - email: from --email (or NULL if not provided)
   - is_active: 1
6. Display success message with created user info (ID, login, full_name)

### Error handling

- Missing required options → clear error message
- Duplicate login → "Error: User with login 'xxx' already exists."
- DB connection error → show error details in dev mode
- Missing config/main.json → "Error: Not a Shipard data source directory"

### Example usage

```bash
cd /opt/shipard/data-sources/abcd-efgh-ijkl-mnop
shpd-ds user-create --login admin --password heslo123 --name "Administrator" --email admin@example.com
```

Expected output:
```
User created successfully.
  ID:    1
  Login: admin
  Name:  Administrator
  Email: admin@example.com
```

### Registration

Register the command in `bin/shpd-ds` alongside existing commands.
Update `src/Command/DataSource/HelpCommand.php` to include `user-create` in the help output.

### Tests

Create `tests/Unit/Command/DataSource/UserCreateCommandTest.php`:
- Test successful user creation (mock DB)
- Test duplicate login detection
- Test missing required options
- Test password is hashed (not stored in plain text)
- Follow the same testing patterns as DsCreateCommandTest (subclassing for testability)

### Conventions

- Use the same constructor pattern as DsUpgradeCommand (optional DI of DataSourceConfig and DataSourceConnection for testing)
- Use Symfony Console options (InputOption::VALUE_REQUIRED)
- PHP 8.5+, strict_types, PSR-4
- All code and comments in English
```
