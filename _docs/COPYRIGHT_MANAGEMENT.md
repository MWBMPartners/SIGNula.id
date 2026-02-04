# Copyright Management System

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

---

## 📋 Overview

This project uses an automated copyright management system that:
- ✅ Adds copyright headers to all code and documentation files
- ✅ Automatically updates copyright years via Git hooks
- ✅ Maintains consistent copyright format across all file types
- ✅ Ensures compliance with intellectual property requirements

---

## 🚀 Quick Start

### Initial Setup (One-Time)

```bash
# 1. Install Git hooks for automatic year updates
bash _scripts/setup-copyright-hooks.sh

# 2. Add copyright headers to all existing files
bash _scripts/add-copyright.sh
```

That's it! Copyright years will now update automatically on every commit.

---

## 📝 Copyright Formats

### PHP Files
```php
<?php
/**
 * SIGNula - Universal Single Sign-On Authentication System
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package SIGNula
 * @version 2.2.0-beta
 */
```

### Markdown Files
```markdown
---

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
```

### SQL Files
```sql
-- ============================================================================
-- SIGNula Database Schema
--
-- Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
--
-- This software is proprietary and confidential. Unauthorized copying,
-- distribution, or use is strictly prohibited.
-- ============================================================================
```

### JavaScript Files
```javascript
/**
 * SIGNula - Universal Single Sign-On Authentication System
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 */
```

---

## 🛠️ Manual Copyright Management

### Add Copyright to All Files

```bash
bash _scripts/add-copyright.sh
```

This will:
- Scan all PHP, JS, SQL, and Markdown files
- Add copyright headers to files without them
- Skip files that already have copyright headers

### Update Copyright Years

```bash
bash _scripts/add-copyright.sh --update-year
```

This will:
- Update existing copyright year ranges to current year
- Change `2025` to `2025-2026` (or current year)
- Process all files with existing copyrights

### Preview Changes (Dry Run)

```bash
bash _scripts/add-copyright.sh --update-year --dry-run
```

This will:
- Show what would be changed
- Not modify any files
- Useful for testing before applying changes

---

## 🔄 Automatic Year Updates (Git Hook)

### How It Works

The pre-commit Git hook automatically:
1. Detects staged files with copyrights
2. Updates year ranges to current year
3. Re-stages modified files
4. Includes them in your commit

### Example

```bash
# Make a change to a file
echo "// comment" >> web/public_html/index.php

# Stage and commit
git add web/public_html/index.php
git commit -m "Update index"

# Output:
✓ Updated copyright year in: web/public_html/index.php

✅ Copyright years automatically updated to 2025-2026
```

### Disable Hook Temporarily

```bash
# Skip hook for a single commit
git commit --no-verify -m "message"

# Or remove the hook
rm .git/hooks/pre-commit
```

### Reinstall Hook

```bash
bash _scripts/setup-copyright-hooks.sh
```

---

## 📂 Files Processed

### PHP Files
- `web/private_html/**/*.php`
- `web/public_html/**/*.php`
- `web/_config/**/*.php`
- `web/_includes/**/*.php`

### Markdown Files
- `*.md` (root directory)
- `_docs/**/*.md`

### SQL Files
- `_sql/**/*.sql`
- `_database/migrations/**/*.sql`

### JavaScript Files
- `web/public_html/assets/js/**/*.js`

---

## 🎯 Year Range Logic

The system automatically calculates the year range:

- **First year (2025)** - Project start year (hardcoded)
- **Last year (current)** - Automatically detected from system date

### Examples

| Current Year | Copyright Display |
|--------------|-------------------|
| 2025 | `Copyright © 2025` |
| 2026 | `Copyright © 2025-2026` |
| 2027 | `Copyright © 2025-2027` |
| 2030 | `Copyright © 2025-2030` |

---

## ⚙️ Script Options

### add-copyright.sh

```bash
bash _scripts/add-copyright.sh [OPTIONS]

Options:
  --update-year    Update copyright year range to current year
  --dry-run        Show what would be changed without modifying files
  --help           Show help message
```

### Examples

```bash
# Add copyright to new files
bash _scripts/add-copyright.sh

# Update all years
bash _scripts/add-copyright.sh --update-year

# Preview year updates
bash _scripts/add-copyright.sh --update-year --dry-run

# Help
bash _scripts/add-copyright.sh --help
```

---

## 🔍 Verification

### Check Copyright Headers

```bash
# Check PHP files
grep -r "Copyright ©" web/private_html/ | head -5

# Check Markdown files
grep "Copyright ©" *.md

# Check SQL files
grep "Copyright ©" _sql/*.sql
```

### Verify Git Hook

```bash
# Check if hook is installed
ls -la .git/hooks/pre-commit

# View hook content
cat .git/hooks/pre-commit
```

---

## 🆘 Troubleshooting

### Hook Not Running

**Problem:** Copyright years not updating on commit

**Solution:**
```bash
# Check if hook exists
ls -la .git/hooks/pre-commit

# Reinstall
bash _scripts/setup-copyright-hooks.sh

# Verify it's executable
chmod +x .git/hooks/pre-commit
```

### Script Permission Denied

**Problem:** `Permission denied` when running script

**Solution:**
```bash
# Make scripts executable
chmod +x _scripts/*.sh
chmod +x _scripts/git-hooks/*
```

### Year Not Updating

**Problem:** Year still shows old year

**Solution:**
```bash
# Manually update all copyrights
bash _scripts/add-copyright.sh --update-year

# Check system date
date +%Y
```

---

## 📚 Best Practices

### For Developers

1. ✅ **Run setup once** when joining the project
   ```bash
   bash _scripts/setup-copyright-hooks.sh
   ```

2. ✅ **Let Git hooks handle year updates** automatically
   - No manual intervention needed
   - Years update on every commit

3. ✅ **Add copyright to new files** before committing
   ```bash
   bash _scripts/add-copyright.sh
   ```

### For New Files

When creating new files:
1. Write your code/documentation
2. Run `bash _scripts/add-copyright.sh` before first commit
3. Git hook will maintain copyright from then on

### Annual Maintenance

At the start of each new year:
1. Git hook automatically updates year on next commit
2. Or manually update all: `bash _scripts/add-copyright.sh --update-year`
3. Verify: `grep -r "Copyright © 2025-$(date +%Y)"`

---

## 🔒 Intellectual Property

### Copyright Notice

**MWBM Partners Ltd (t/a MWservices)**
- All code, documentation, and assets are proprietary
- Unauthorized copying, distribution, or use is strictly prohibited
- Copyright © 2025-2026 and beyond

### License

This software is **proprietary and confidential**. All rights reserved.

For licensing inquiries, contact:
- **Website:** https://SIGNula.com
- **Email:** support@signula.com

---

## 📞 Support

For issues with the copyright management system:

1. Check this documentation
2. Review troubleshooting section
3. Contact: support@signula.com

---

**Last Updated:** February 4, 2026
**System Version:** 1.0.0

---

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
