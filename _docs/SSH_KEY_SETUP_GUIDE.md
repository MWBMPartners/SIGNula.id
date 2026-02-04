# SSH Key Setup Guide for SFTP

**Purpose:** Set up SSH key authentication for secure, password-free SFTP deployment

**Security:** ✅ SSH keys are stored in `~/.ssh/` (NOT in your project)

---

## 🔑 What are SSH Keys?

SSH keys provide a secure way to authenticate without passwords:
- **Private Key** - Stays on your Mac (never share this!)
- **Public Key** - Uploaded to your server (safe to share)

Think of it like a lock and key:
- Public key = lock (on the server)
- Private key = key (on your Mac)

---

## 🚀 Step 1: Check for Existing SSH Keys

First, see if you already have SSH keys:

```bash
ls -la ~/.ssh/
```

**If you see these files, you already have keys:**
- `id_rsa` or `id_ed25519` - Private key ✅
- `id_rsa.pub` or `id_ed25519.pub` - Public key ✅

**Skip to Step 3** if you have them!

---

## 🔧 Step 2: Generate New SSH Keys

If you don't have keys, create them:

### **Option A: Modern Ed25519 Key (Recommended)**

```bash
# Generate a new Ed25519 key
ssh-keygen -t ed25519 -C "your-email@example.com"
```

**What it asks:**

1. **Where to save:** Just press `Enter` (default: `~/.ssh/id_ed25519`)
   ```
   Enter file in which to save the key (/Users/lance/.ssh/id_ed25519):
   ```

2. **Passphrase:** Enter a strong password (or press Enter for no password)
   ```
   Enter passphrase (empty for no passphrase):
   Enter same passphrase again:
   ```

   ⚠️ **Recommendation:** Use a passphrase for extra security!

**Result:** Two files created:
- `~/.ssh/id_ed25519` - Private key (keep secret!)
- `~/.ssh/id_ed25519.pub` - Public key (upload to server)

### **Option B: RSA Key (For older servers)**

```bash
# Generate a 4096-bit RSA key
ssh-keygen -t rsa -b 4096 -C "your-email@example.com"
```

**Result:** Two files created:
- `~/.ssh/id_rsa` - Private key
- `~/.ssh/id_rsa.pub` - Public key

---

## 🔒 Step 3: Secure Your Private Key

Set correct permissions (important!):

```bash
# For Ed25519
chmod 600 ~/.ssh/id_ed25519
chmod 644 ~/.ssh/id_ed25519.pub

# For RSA
chmod 600 ~/.ssh/id_rsa
chmod 644 ~/.ssh/id_rsa.pub
```

**Why?** Private keys must be readable only by you, or SSH will refuse to use them.

---

## 📤 Step 4: Copy Public Key to Server

You need to add your **public key** to the server. There are several methods:

### **Method A: ssh-copy-id (Easiest)**

```bash
# For Ed25519
ssh-copy-id -i ~/.ssh/id_ed25519.pub username@server.com

# For RSA
ssh-copy-id -i ~/.ssh/id_rsa.pub username@server.com
```

Enter your server password when prompted. Done! ✅

### **Method B: Manual Copy (If ssh-copy-id not available)**

**Step 1:** Display your public key:
```bash
# For Ed25519
cat ~/.ssh/id_ed25519.pub

# For RSA
cat ~/.ssh/id_rsa.pub
```

**Step 2:** Copy the output (entire line, looks like):
```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJw... your-email@example.com
```

**Step 3:** Log into your server:
```bash
ssh username@server.com
```

**Step 4:** Add the key to authorized_keys:
```bash
# Create .ssh directory if it doesn't exist
mkdir -p ~/.ssh
chmod 700 ~/.ssh

# Add your public key
echo "YOUR_PUBLIC_KEY_HERE" >> ~/.ssh/authorized_keys

# Set correct permissions
chmod 600 ~/.ssh/authorized_keys

# Exit server
exit
```

### **Method C: Dreamhost Control Panel**

1. Log into Dreamhost Panel
2. Go to **Users** → **Manage Users**
3. Click **Edit** next to your user
4. Scroll to **SSH Keys**
5. Paste your public key
6. Click **Save**

---

## ✅ Step 5: Test SSH Connection

Test that key authentication works:

```bash
ssh username@server.com
```

**Success:** You log in WITHOUT entering a password! 🎉

**If it asks for password:** Something went wrong. Check:
1. Public key is in `~/.ssh/authorized_keys` on server
2. Permissions are correct (600 for private key, 644 for public key)
3. Using correct username and server

---

## 🔧 Step 6: Configure sftp.json

Update your `web/.vscode/sftp.json`:

### **For Ed25519 Key:**
```json
{
    "name": "SIGNula Production",
    "host": "server.com",
    "port": 22,
    "username": "your-username",
    "password": "",
    "privateKeyPath": "~/.ssh/id_ed25519",
    "passphrase": "your-passphrase-if-you-set-one",
    "remotePath": "/home/your-username/signulo.id",
    "uploadOnSave": false
}
```

### **For RSA Key:**
```json
{
    "name": "SIGNula Production",
    "host": "server.com",
    "port": 22,
    "username": "your-username",
    "password": "",
    "privateKeyPath": "~/.ssh/id_rsa",
    "passphrase": "your-passphrase-if-you-set-one",
    "remotePath": "/home/your-username/signulo.id",
    "uploadOnSave": false
}
```

**Important:**
- Leave `password` empty
- Set `privateKeyPath` to your private key
- Add `passphrase` if you set one during key generation (or leave empty)

---

## 🧪 Step 7: Test SFTP Connection

In VS Code:

1. **Open web/ folder**
   ```bash
   code web/
   ```

2. **Open Command Palette**
   - Mac: `Cmd + Shift + P`
   - Windows: `Ctrl + Shift + P`

3. **Type:** `SFTP: List`

4. **Success:** You see your remote directory! 🎉

5. **If it fails:** Check:
   - `sftp.json` has correct path to private key
   - Server host and username are correct
   - SSH key is set up on server

---

## 🔐 Security Best Practices

### ✅ **DO:**
- ✅ Use Ed25519 keys (modern, secure, fast)
- ✅ Use a passphrase on your private key
- ✅ Keep private key on your Mac only
- ✅ Set correct permissions (600 for private, 644 for public)
- ✅ Use different keys for different servers (optional but recommended)

### ❌ **DON'T:**
- ❌ Share your private key with anyone
- ❌ Commit private keys to Git (they're in ~/.ssh anyway, not in project)
- ❌ Email your private key
- ❌ Upload private key to server
- ❌ Use the same password for your passphrase and server

---

## 📋 Quick Reference

### **Generate New Key**
```bash
ssh-keygen -t ed25519 -C "your-email@example.com"
```

### **Copy to Server**
```bash
ssh-copy-id -i ~/.ssh/id_ed25519.pub username@server.com
```

### **Test Connection**
```bash
ssh username@server.com
```

### **View Your Public Key**
```bash
cat ~/.ssh/id_ed25519.pub
```

### **List Your Keys**
```bash
ls -la ~/.ssh/
```

---

## 🆘 Troubleshooting

### **Problem: Permission denied (publickey)**

**Solution 1:** Check key permissions
```bash
chmod 600 ~/.ssh/id_ed25519
chmod 644 ~/.ssh/id_ed25519.pub
```

**Solution 2:** Check authorized_keys on server
```bash
ssh username@server.com
chmod 600 ~/.ssh/authorized_keys
cat ~/.ssh/authorized_keys  # Should show your public key
```

**Solution 3:** Check SSH directory permissions on server
```bash
chmod 700 ~/.ssh
```

### **Problem: VS Code SFTP asks for password**

**Solution:** Check sftp.json:
```json
{
    "password": "",                      // Must be empty!
    "privateKeyPath": "~/.ssh/id_ed25519",  // Correct path?
    "passphrase": "your-passphrase"     // If you set one
}
```

### **Problem: "Agent admitted failure to sign"**

**Solution:** Add key to SSH agent
```bash
# Start SSH agent
eval "$(ssh-agent -s)"

# Add your key
ssh-add ~/.ssh/id_ed25519

# For Mac, add to keychain (remembers passphrase)
ssh-add --apple-use-keychain ~/.ssh/id_ed25519
```

### **Problem: VS Code SFTP not using correct key**

**Solution:** Specify full path (no ~)
```json
{
    "privateKeyPath": "/Users/lance.manasse/.ssh/id_ed25519"
}
```

---

## 🎯 Complete Example (Dreamhost)

Assuming:
- Server: `server.dreamhost.com`
- Username: `signula`
- Email: `lance@example.com`

### **1. Generate Key**
```bash
ssh-keygen -t ed25519 -C "lance@example.com"
# Press Enter to accept default location
# Enter passphrase (optional but recommended)
```

### **2. Copy to Server**
```bash
ssh-copy-id -i ~/.ssh/id_ed25519.pub signula@server.dreamhost.com
# Enter your Dreamhost password
```

### **3. Test Connection**
```bash
ssh signula@server.dreamhost.com
# Should log in WITHOUT password!
```

### **4. Configure sftp.json**
```json
{
    "name": "SIGNula Production",
    "host": "server.dreamhost.com",
    "port": 22,
    "username": "signula",
    "password": "",
    "privateKeyPath": "~/.ssh/id_ed25519",
    "passphrase": "your-passphrase",
    "remotePath": "/home/signula/signulo.id",
    "uploadOnSave": false
}
```

### **5. Test in VS Code**
- Open `web/` folder
- `Cmd+Shift+P` → `SFTP: List`
- Should see remote files! ✅

---

## 🔄 Multiple Servers / Keys

If you need different keys for different servers:

### **Generate with custom name:**
```bash
ssh-keygen -t ed25519 -f ~/.ssh/signula_prod -C "lance@example.com"
ssh-keygen -t ed25519 -f ~/.ssh/signula_dev -C "lance@example.com"
```

### **Create SSH config:**
```bash
nano ~/.ssh/config
```

**Add:**
```
Host signula-prod
    HostName server.dreamhost.com
    User signula
    IdentityFile ~/.ssh/signula_prod

Host signula-dev
    HostName dev.dreamhost.com
    User signula
    IdentityFile ~/.ssh/signula_dev
```

**Then in sftp.json:**
```json
{
    "host": "signula-prod",  // Uses SSH config
    "username": "signula"
}
```

---

## ✅ Checklist

Use this to verify everything is set up correctly:

- [ ] SSH key generated (`~/.ssh/id_ed25519` or `~/.ssh/id_rsa`)
- [ ] Private key permissions set to 600
- [ ] Public key copied to server (`~/.ssh/authorized_keys`)
- [ ] Server authorized_keys permissions set to 600
- [ ] SSH connection works without password
- [ ] sftp.json configured with private key path
- [ ] VS Code SFTP connection tested
- [ ] Private keys NOT in Git repo (they're in `~/.ssh/`, not in project)

---

## 🎓 Learn More

- [GitHub SSH Key Guide](https://docs.github.com/en/authentication/connecting-to-github-with-ssh)
- [Dreamhost SSH Keys](https://help.dreamhost.com/hc/en-us/articles/216499537-How-to-configure-passwordless-login-in-Mac-OS-X-and-Linux)
- [SSH Academy](https://www.ssh.com/academy/ssh-keys)

---

**Ready!** Your SSH keys are now set up for secure, password-free SFTP deployment! 🚀

---

**Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.**

This documentation is proprietary and confidential. Unauthorized copying, distribution, or use is strictly prohibited.
