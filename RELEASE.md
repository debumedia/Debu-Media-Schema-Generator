# Release Process

This document describes how to create a new release of the AI JSON-LD Generator plugin.

## Quick Release Command

For convenience, you can ask Claude Code:
```
Create release X.X.X
```

Claude will handle all the steps below automatically.

---

## Manual Release Steps

### 1. Update Version Number

Update the version in `ai-jsonld-generator.php`:
```php
* Version: X.X.X
```
```php
define( 'AI_JSONLD_VERSION', 'X.X.X' );
```

### 2. Update Changelog

Add entry to `release/ai-jsonld-generator/readme.txt`:
```
== Changelog ==

= X.X.X =
* New feature description
* Bug fix description
```

### 3. Build Release Package

```bash
# Clean previous release
rm -rf release/ai-jsonld-generator release/ai-jsonld-generator-X.X.X.zip

# Copy plugin files
mkdir -p release/ai-jsonld-generator
cp -r ai-jsonld-generator.php uninstall.php includes providers assets release/ai-jsonld-generator/
cp release/ai-jsonld-generator/readme.txt release/ai-jsonld-generator/ 2>/dev/null || true
mkdir -p release/ai-jsonld-generator/languages

# Create zip
cd release && zip -r ai-jsonld-generator-X.X.X.zip ai-jsonld-generator
```

### 4. Commit Changes

```bash
git add .
git commit -m "Release vX.X.X

- Feature/fix descriptions here

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
git push
```

### 5. Create Git Tag

```bash
git tag -a vX.X.X -m "Release vX.X.X"
git push origin vX.X.X
```

### 6. Create GitHub Release

```bash
gh release create vX.X.X \
  --title "vX.X.X - Release Title" \
  --notes "Release notes here..." \
  release/ai-jsonld-generator-X.X.X.zip
```

---

## Version Numbering

We follow [Semantic Versioning](https://semver.org/):

- **MAJOR** (X.0.0): Breaking changes
- **MINOR** (0.X.0): New features, backwards compatible
- **PATCH** (0.0.X): Bug fixes, backwards compatible

---

## Release Checklist

- [ ] Version updated in main plugin file
- [ ] Changelog updated in readme.txt
- [ ] All changes committed and pushed
- [ ] Release zip created and tested
- [ ] Git tag created and pushed
- [ ] GitHub release created with zip attachment
- [ ] Release tested on WordPress site
