# 🚀 Directory Reorganization - Quick Start

**Purpose:** Reorganize SIGNula project structure for easier SFTP deployment

---

## ⚡ Quick Start (Automated)

**Run this single command to automatically reorganize:**

```bash
bash _scripts/reorganize-structure.sh
```

This script will:
- ✅ Create automatic backup
- ✅ Create `web/` directory
- ✅ Move all server files into `web/`
- ✅ Move `_includes` out of `public_html`
- ✅ Update `.gitignore`
- ✅ Create SFTP config template
- ✅ Create verification test script

**Total time:** ~30 seconds

---

## 📁 What Changes?

### BEFORE:
```
SIGNula/
├── _config/         ⬆️ UPLOAD
├── _database/       ⬆️ UPLOAD
├── private_html/    ⬆️ UPLOAD
├── public_html/     ⬆️ UPLOAD
├── _docs/           🚫 DON'T UPLOAD
├── _tests/          🚫 DON'T UPLOAD
└── README.md        🚫 DON'T UPLOAD
```

### AFTER:
```
SIGNula/
├── web/                    🎯 SYNC THIS FOLDER TO SERVER
│   ├── _config/
│   ├── _includes/          (moved from public_html)
│   ├── _database/
│   ├── private_html/
│   └── public_html/        (your document root)
├── _docs/                  (stays local)
├── _tests/                 (stays local)
└── README.md               (stays local)
```

---

## ✅ Verification

After running the script:

```bash
# Test that everything works
php web/public_html/test-reorganization.php
```

Expected output:
```
✅ ALL TESTS PASSED - Reorganization successful!
```

---

## 🔧 VS Code SFTP Setup

1. **Open web/ folder in VS Code:**
   ```bash
   code web/
   ```

2. **Copy SFTP config template:**
   ```bash
   cp web/.vscode/sftp.json.example web/.vscode/sftp.json
   ```

3. **Edit with your server details:**
   ```json
   {
       "host": "your-server.com",
       "username": "your-username",
       "remotePath": "/home/username/signulo.id"
   }
   ```

4. **Sync to server:**
   - Right-click `web/` folder
   - Select "SFTP: Sync Local → Remote"

---

## 📚 Full Documentation

For detailed information, see:
- [`_docs/DIRECTORY_REORGANIZATION_GUIDE.md`](_docs/DIRECTORY_REORGANIZATION_GUIDE.md) - Complete guide with troubleshooting

---

## 🆘 Rollback

If something goes wrong:

```bash
# The script creates automatic backup
# Find it in parent directory: SIGNula-backup-YYYYMMDD_HHMMSS.tar.gz

cd ..
rm -rf SIGNula
tar -xzf SIGNula-backup-YYYYMMDD_HHMMSS.tar.gz
```

---

## 🎯 Benefits

✅ **One command deployment** - Just sync `web/` folder
✅ **Cleaner project** - Docs and tests separate
✅ **Better security** - Clear separation of server vs local files
✅ **No mistakes** - Can't accidentally upload .git or tests
✅ **Faster syncing** - Smaller upload footprint

---

**Ready?** Run: `bash _scripts/reorganize-structure.sh`

---

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
