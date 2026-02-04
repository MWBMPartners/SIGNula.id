# Version Management Guide

This document explains how SIGNula uses version numbering, how to create releases, and best practices for version management with GitHub integration.

---

## Table of Contents

- [Semantic Versioning](#semantic-versioning)
- [Version Files](#version-files)
- [Version Management Scripts](#version-management-scripts)
- [Release Process](#release-process)
- [GitHub Integration](#github-integration)
- [Best Practices](#best-practices)
- [Examples](#examples)

---

## Semantic Versioning

SIGNula follows [Semantic Versioning 2.0.0](https://semver.org/) (SemVer):

```
MAJOR.MINOR.PATCH-prerelease+build
```

### Version Components

| Component | When to Increment | Example |
|-----------|-------------------|---------|
| **MAJOR** | Breaking changes, incompatible API changes | 1.0.0 → 2.0.0 |
| **MINOR** | New features (backward compatible) | 1.0.0 → 1.1.0 |
| **PATCH** | Bug fixes (backward compatible) | 1.0.0 → 1.0.1 |
| **prerelease** | Alpha, beta, rc (optional) | 1.0.0-beta |
| **build** | Build metadata (optional, rarely used) | 1.0.0+20130313 |

### Pre-release Identifiers

| Identifier | Purpose | Stability |
|------------|---------|-----------|
| **alpha** | Early testing, unstable, incomplete features | Very Low |
| **beta** | Feature complete, testing phase, may have bugs | Medium |
| **rc** (release candidate) | Near-final, production testing | High |

### Version Examples

```
1.0.0              # First stable release
1.0.1              # Patch release (bug fix)
1.1.0              # Minor release (new feature)
2.0.0              # Major release (breaking changes)
2.0.0-alpha        # Alpha pre-release
2.0.0-alpha.1      # Numbered alpha
2.0.0-beta         # Beta pre-release
2.0.0-beta.2       # Second beta
2.0.0-rc.1         # Release candidate 1
2.0.0              # Final stable release
```

### When to Increment

**MAJOR version** when you:
- Remove or change API endpoints
- Change database schema in breaking ways
- Remove features or functionality
- Make incompatible changes to authentication
- Change configuration format incompatibly

**MINOR version** when you:
- Add new API endpoints
- Add new features
- Add new database tables (non-breaking)
- Add new authentication methods
- Deprecate features (but don't remove yet)

**PATCH version** when you:
- Fix bugs
- Update documentation
- Improve performance
- Refactor code (no external changes)
- Update dependencies (patch/minor)

---

## Version Files

### VERSION File

Single-line file containing current version:

**Location:** `/VERSION`

**Format:** `MAJOR.MINOR.PATCH-prerelease`

**Example:**
```
2.0.1-beta
```

### CHANGELOG.md

Detailed change history following [Keep a Changelog](https://keepachangelog.com/) format.

**Location:** `/CHANGELOG.md`

**Categories:**
- **Added**: New features
- **Changed**: Changes to existing functionality
- **Deprecated**: Soon-to-be removed features
- **Removed**: Removed features
- **Fixed**: Bug fixes
- **Security**: Security fixes or improvements

**Example Entry:**
```markdown
## [2.0.1-beta] - 2026-02-03

### Added
- OAuth multi-account support with accountType and emailDomain fields
- Domain-based filtering for third-party services

### Changed
- OAuthController now supports multiple accounts per provider

### Security
- Prevents duplicate external accounts across SIGNula accounts
```

### PROJECT_PROGRESS.md

Tracks project development progress and milestones.

**Version References:**
- Header: `**Current Version:** 2.0.1-beta`
- Last Updated: `**Last Updated:** 2026-02-03`
- Recent Updates section with dated entries

---

## Version Management Scripts

Located in `_scripts/` directory:

### 1. version-info.sh

**Purpose:** Display current version information

**Usage:**
```bash
cd _scripts
./version-info.sh
```

**Output:**
- Current version and type
- Version components breakdown
- Git tag status
- GitHub release status
- Latest changelog entry
- Available version bumps
- Quick commands

### 2. version-bump.sh

**Purpose:** Increment version numbers automatically

**Usage:**
```bash
cd _scripts
./version-bump.sh [TYPE]
```

**Types:**
- `major` - Increment major version
- `minor` - Increment minor version
- `patch` - Increment patch version
- `beta` - Add/increment beta identifier
- `rc` - Add/increment release candidate
- `release` - Remove pre-release identifier

**Examples:**
```bash
# Current: 2.0.1-beta

./version-bump.sh patch     # → 2.0.2-beta (increments patch)
./version-bump.sh release   # → 2.0.1 (removes beta)
./version-bump.sh minor     # → 2.1.0 (increments minor)
./version-bump.sh major     # → 3.0.0 (increments major)
./version-bump.sh beta      # → 2.0.1-beta.1 (adds number if already beta)
./version-bump.sh rc        # → 2.0.1-rc.1 (changes to rc)
```

**What it does:**
1. Reads current version from VERSION file
2. Calculates new version based on type
3. Prompts for confirmation
4. Updates VERSION file
5. Updates PROJECT_PROGRESS.md (version and date)
6. Shows next steps

### 3. create-release.sh

**Purpose:** Create GitHub releases with changelog notes

**Prerequisites:**
- GitHub CLI (`gh`) installed
- Authenticated with GitHub: `gh auth login`

**Usage:**
```bash
cd _scripts
./create-release.sh [VERSION]
```

**Examples:**
```bash
# Use VERSION file
./create-release.sh

# Specific version
./create-release.sh v2.0.1-beta
```

**What it does:**
1. Verifies GitHub CLI is installed and authenticated
2. Checks if Git tag exists (creates if needed)
3. Extracts release notes from CHANGELOG.md
4. Shows release notes preview
5. Creates GitHub release (marks pre-releases appropriately)
6. Opens release page in browser

---

## Release Process

### Standard Release Workflow

Follow these steps for every release:

#### 1. Prepare Changes

```bash
# Make your code changes
git add .
git commit -m "feat: Add new feature"
```

#### 2. Update CHANGELOG.md

Add entry for the new version:

```markdown
## [2.0.2-beta] - 2026-02-03

### Added
- New feature description

### Fixed
- Bug fix description
```

#### 3. Bump Version

```bash
cd _scripts
./version-bump.sh patch  # or minor, major, etc.
```

This updates:
- `VERSION` file
- `PROJECT_PROGRESS.md` (version and date)

#### 4. Review Changes

```bash
# Check what changed
git diff

# View version info
./version-info.sh
```

#### 5. Commit Version Bump

```bash
cd ..
git add VERSION PROJECT_PROGRESS.md CHANGELOG.md
git commit -m "chore: Release v2.0.2-beta"
```

#### 6. Create Git Tag

```bash
VERSION=$(cat VERSION)
git tag -a "v$VERSION" -m "Release v$VERSION"
```

#### 7. Push to GitHub

```bash
# Push commits and tags
git push origin main --tags
```

#### 8. Create GitHub Release

```bash
cd _scripts
./create-release.sh
```

This will:
- Create GitHub release
- Attach changelog notes
- Mark as pre-release if applicable
- Open release page

#### 9. Verify Release

- Check GitHub release page
- Verify changelog is correct
- Test installation from release

#### 10. Announce

- Notify team/stakeholders
- Update documentation if needed
- Deploy to production if applicable

---

## GitHub Integration

### Git Tags

Every version should have a corresponding Git tag:

```bash
# Create annotated tag
git tag -a v2.0.1-beta -m "Release v2.0.1-beta"

# Push tag to remote
git push origin v2.0.1-beta

# Or push all tags
git push origin --tags

# List tags
git tag -l

# Delete tag (local)
git tag -d v2.0.1-beta

# Delete tag (remote)
git push origin --delete v2.0.1-beta
```

### GitHub Releases

GitHub Releases are created from Git tags and include:
- Release title
- Release notes (from CHANGELOG.md)
- Pre-release flag (for alpha/beta/rc)
- Download assets (automatically generated)

**Manual Creation:**
1. Go to GitHub repository
2. Click "Releases" → "Draft a new release"
3. Choose a tag (or create new)
4. Add title: "Release v2.0.1-beta"
5. Add release notes from CHANGELOG.md
6. Check "Set as a pre-release" if applicable
7. Publish release

**Using GitHub CLI:**
```bash
# Create release
gh release create v2.0.1-beta \
    --title "Release v2.0.1-beta" \
    --notes-file release-notes.txt \
    --prerelease

# View release
gh release view v2.0.1-beta

# Edit release
gh release edit v2.0.1-beta --notes "Updated notes"

# Delete release
gh release delete v2.0.1-beta

# List releases
gh release list
```

### Comparing Versions

GitHub can show differences between versions:

```
https://github.com/MWBMPartners/SIGNula.id/compare/v2.0.0-beta...v2.0.1-beta
```

These links are automatically added to CHANGELOG.md.

---

## Best Practices

### 1. Version Early, Version Often

- Create releases regularly, not just for major milestones
- Use pre-release versions during development
- Tag every significant state

### 2. Keep CHANGELOG.md Updated

- Update CHANGELOG.md as you make changes, not just at release time
- Use "Unreleased" section for work-in-progress
- Be specific about what changed and why

### 3. Use Semantic Versioning Correctly

- Don't increment major version for minor changes
- Use pre-release identifiers appropriately
- Be honest about breaking changes

### 4. Tag Consistently

- Always use 'v' prefix for tags (v2.0.1, not 2.0.1)
- Use annotated tags, not lightweight tags
- Include release notes in tag messages

### 5. Test Before Releasing

- Run all tests
- Verify database migrations
- Check API endpoints
- Review documentation

### 6. Automate When Possible

- Use scripts for version bumping
- Automate release note generation
- Use CI/CD for testing and deployment

### 7. Document Breaking Changes

- Clearly mark breaking changes in CHANGELOG.md
- Provide migration guides
- Announce breaking changes prominently

### 8. Pre-release Workflow

```
Feature Branch → alpha → beta → rc → stable
```

- **alpha**: Internal testing, expect bugs
- **beta**: External testing, feature complete
- **rc**: Final testing, near-production
- **stable**: Production release

---

## Examples

### Example 1: Bug Fix Release

```bash
# Current version: 2.0.1-beta

# 1. Fix bug and commit
git commit -am "fix: Correct OAuth token validation"

# 2. Update CHANGELOG.md
# Add "Fixed: OAuth token validation bug" under [Unreleased]

# 3. Bump version (patch)
cd _scripts && ./version-bump.sh patch
# New version: 2.0.2-beta

# 4. Commit and tag
cd ..
git add VERSION CHANGELOG.md PROJECT_PROGRESS.md
git commit -m "chore: Release v2.0.2-beta"
git tag -a v2.0.2-beta -m "Release v2.0.2-beta"

# 5. Push and create release
git push origin main --tags
cd _scripts && ./create-release.sh
```

### Example 2: New Feature Release

```bash
# Current version: 2.0.1-beta

# 1. Implement feature and commit
git commit -am "feat: Add email domain filtering"

# 2. Update CHANGELOG.md with new feature

# 3. Bump version (minor)
cd _scripts && ./version-bump.sh minor
# New version: 2.1.0-beta

# 4. Follow standard release process
```

### Example 3: Beta to Stable

```bash
# Current version: 2.0.1-beta

# 1. Final testing and bug fixes
# 2. Update CHANGELOG.md

# 3. Remove beta identifier
cd _scripts && ./version-bump.sh release
# New version: 2.0.1

# 4. Release as stable (not pre-release)
git add VERSION CHANGELOG.md PROJECT_PROGRESS.md
git commit -m "chore: Release v2.0.1 (stable)"
git tag -a v2.0.1 -m "Release v2.0.1 - First stable release of 2.0 series"
git push origin main --tags
./create-release.sh  # Creates as stable release (no pre-release flag)
```

### Example 4: Major Version (Breaking Changes)

```bash
# Current version: 2.5.3

# 1. Implement breaking changes
git commit -am "feat!: Remove deprecated OAuth v1 support"

# 2. Document breaking changes in CHANGELOG.md
# Add detailed migration guide

# 3. Bump to next major version
cd _scripts && ./version-bump.sh major
# New version: 3.0.0

# 4. Release as stable (after thorough testing)
```

---

## Troubleshooting

### Issue: Wrong Version Bumped

**Solution:** Manually edit VERSION file and PROJECT_PROGRESS.md

```bash
echo "2.0.1-beta" > VERSION
# Edit PROJECT_PROGRESS.md manually
```

### Issue: Tag Already Exists

**Solution:** Delete and recreate tag

```bash
git tag -d v2.0.1-beta              # Delete local
git push origin --delete v2.0.1-beta  # Delete remote
git tag -a v2.0.1-beta -m "Release v2.0.1-beta"
git push origin v2.0.1-beta
```

### Issue: Need to Edit Release After Publishing

**Solution:** Use GitHub CLI or web interface

```bash
# Edit release notes
gh release edit v2.0.1-beta --notes "Updated release notes"

# Or via web: GitHub → Releases → Edit release
```

### Issue: Forgot to Update CHANGELOG.md

**Solution:** Update and re-release

```bash
# Update CHANGELOG.md
git add CHANGELOG.md
git commit -m "docs: Update CHANGELOG for v2.0.1-beta"
git push

# Update GitHub release
gh release edit v2.0.1-beta --notes-file updated-notes.txt
```

---

## References

- [Semantic Versioning](https://semver.org/)
- [Keep a Changelog](https://keepachangelog.com/)
- [GitHub CLI Documentation](https://cli.github.com/manual/)
- [Git Tagging](https://git-scm.com/book/en/v2/Git-Basics-Tagging)

---

**Last Updated:** 2026-02-03
**Version:** 1.0.0
