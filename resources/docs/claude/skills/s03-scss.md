# S03 SCSS Development

This skill provides instructions for working with SCSS in the lbwp-standard-03 theme (often called "s03").

## Overview

The lbwp-standard-03 theme is a parent WordPress theme that provides the base styling and components for child themes. All SCSS for this theme is located in:

```
wp-content/themes/lbwp-standard-03/assets/styles/
```

### Directory Structure

```
scss/
├── settings/              # Theme settings and variables
│   ├── _s03-settings.scss
│   └── __s03-colors.scss
├── blocks/               # Gutenberg block styles
│   ├── editor/          # Editor-specific styles
│   ├── aboon/           # Aboon-specific blocks
│   └── _*.scss          # Individual block files
├── s03-components/      # Reusable components
│   ├── _buttons.scss
│   ├── _forms.scss
│   ├── _typo.scss
│   ├── _grid.scss
│   └── ...
├── s03-themes/          # Theme-specific overrides
│   └── aboon/
│       └── components/
│           └── _lbwp-forms.scss
├── parts/               # Partial styles (cards, etc.)
├── _mixins-s03.scss     # S03-specific mixins
├── __settings-s03.scss  # Settings aggregator
├── editor.scss          # Editor styles entry point
├── base.scss            # Base styles entry point
└── theme.scss           # Main theme entry point (if exists)
```

## Starting the SCSS Watcher

**ALWAYS check if the watcher is running before making SCSS changes.**

### Check if Watcher is Running

```bash
ps aux | grep -i scss | grep -v grep
```

If you see a process like `npm run scss` or `sass --watch`, the watcher is already running.

### Start the Watcher

If not running, start it:

```bash
cd /var/www/lbwp/wp-content/themes/lbwp-standard-03/assets/styles
./watch-s03.sh
```

**Note:** The watcher runs in the foreground. If you need to start it programmatically, use:

```bash
cd /var/www/lbwp/wp-content/themes/lbwp-standard-03/assets/styles && npm run scss
```

The watcher compiles SCSS files from `scss/` directory to the current directory (`.`) and generates:
- `editor.css` - Editor styles
- `base.css` - Base styles
- `theme.css` - Main theme styles (if applicable)

## Coding Style Guidelines

### 1. CSS Custom Properties (CSS Variables)

**ALWAYS define customizable values as CSS custom properties in `:root`:**

```scss
:root {
  // Component-specific variables
  --s03-button-padding: 0.75rem 1.5rem;
  --s03-button-border-radius: var(--s03-default-border-radius);
  --s03-button-font-size: 1rem;

  // Use references to other variables when appropriate
  --s03-primary-hover-color: var(--s03-primary-color);

  // Use SCSS variables for calculations
  --s03-grid-gap: #{$grid-gutter-width};
}
```

### 2. Naming Conventions

**Use BEM-like naming with s03 prefix:**

```scss
// Block
.s03-component-name { }

// Block with element
.s03-component-name__element { }

// Block with modifier
.s03-component-name--variant { }

// Example from actual code:
.s03-persons-block { }
.s03-persons-block__listing { }
.s03-person-image { }
```

### 3. Responsive Design

**Use Bootstrap mixins for breakpoints:**

```scss
.s03-component {
  // Mobile first - default styles

  @include media-breakpoint-up(md) {
    // Tablet and up (≥768px)
  }

  @include media-breakpoint-up(lg) {
    // Desktop and up (≥992px)
  }

  @include media-breakpoint-up(xl) {
    // Large desktop and up (≥1200px)
  }
}
```

### 4. Grid Layouts

**Use CSS Grid with minmax for responsive columns:**

```scss
.s03-component__grid {
  display: grid;
  gap: var(--s03-component-gap);

  @include media-breakpoint-up(md) {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  @include media-breakpoint-up(lg) {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
```

### 5. Component Structure

**Organize component files with this structure:**

```scss
// 1. CSS Variables in :root
:root {
  --s03-component-property: value;
}

// 2. Block-level styles
.wp-block-s03-component { }

// 3. Variants/modifiers
.wp-block-s03-component.is-variant { }

// 4. Elements
.s03-component__element { }

// 5. Nested styles with proper specificity
.s03-component {
  .s03-component__element {
    // Nested element styles
  }
}
```

### 6. File Naming

- Prefix partials with `_` (e.g., `_buttons.scss`)
- Use `s03-` prefix for s03-specific files (e.g., `_s03-person.scss`)
- Use descriptive names (e.g., `_lbwp-forms.scss`, not `_forms2.scss`)

### 7. Imports

**Import order matters:**

```scss
// 1. Framework/library imports
@import "lbwp/lib";

// 2. Settings
@import "settings/_s03-settings";

// 3. Mixins
@import "mixins-s03";

// 4. Bootstrap (if needed)
@import "bootstrap/4.5.0/scss/grid.scss";

// 5. Components
@import "s03-components/buttons";

// 6. Blocks
@import "blocks/_all";

// 7. Theme-specific overrides
@import "s03-themes/aboon/components/filter";
```

## Common Patterns

### Forms (LBWP Forms)

When styling LBWP forms, target the `.lbwp-form` container or `.lbwp-form-override`:

```scss
.lbwp-form {
  .label-checkbox {
    // Radio/checkbox label styles
  }

  input:not([type="submit"]):not([type="radio"]):not([type="checkbox"]) {
    // Text input styles
  }
}
```

### Button Styles

```scss
.btn,
.s03-button {
  padding: var(--s03-button-padding);
  border-radius: var(--s03-button-border-radius);

  &:hover {
    // Hover state
  }

  &.btn--primary {
    // Primary variant
  }
}
```

### Accessibility

**Always include focus states:**

```scss
.s03-component {
  &:focus,
  &:focus-visible {
    outline: 2px solid var(--s03-focus-color);
    outline-offset: 2px;
  }
}
```

## Testing with Chrome DevTools MCP

When provided with a URL for testing, use the Chrome DevTools MCP to verify your changes:

### 1. Navigate to the URL

```
Use: mcp__puppeteer__puppeteer_navigate
- Set allowDangerous: true
- Set launchOptions with sandbox disabled for local environments
- Add --ignore-certificate-errors for local SSL
```

### 2. Take Screenshots

```
Use: mcp__puppeteer__puppeteer_screenshot
- Take before/after screenshots
- Screenshot specific selectors if needed
- Use appropriate width/height (e.g., 1200x1000)
```

### 3. Evaluate Styles

```
Use: mcp__puppeteer__puppeteer_evaluate
- Check computed styles with window.getComputedStyle()
- Verify CSS classes are applied
- Test interactive states (hover, focus, etc.)
```

### 4. Test Interactions

```
Use: mcp__puppeteer__puppeteer_click
Use: mcp__puppeteer__puppeteer_hover
- Test hover states
- Test click interactions
- Verify state changes
```

### Example Testing Workflow

```javascript
// 1. Navigate to page
mcp__puppeteer__puppeteer_navigate({
  url: "https://example.local/page",
  allowDangerous: true,
  launchOptions: {
    headless: true,
    args: ["--no-sandbox", "--disable-setuid-sandbox", "--ignore-certificate-errors"]
  }
});

// 2. Check if styles are applied
mcp__puppeteer__puppeteer_evaluate({
  script: `
    const element = document.querySelector('.s03-component');
    const styles = window.getComputedStyle(element);
    return {
      padding: styles.padding,
      backgroundColor: styles.backgroundColor,
      display: styles.display
    };
  `
});

// 3. Take screenshot
mcp__puppeteer__puppeteer_screenshot({
  name: "component-styled",
  width: 1200,
  height: 1000
});

// 4. Test hover state
mcp__puppeteer__puppeteer_hover({ selector: ".s03-button" });
mcp__puppeteer__puppeteer_screenshot({
  name: "button-hover",
  width: 800,
  height: 600
});
```

## Workflow Summary

1. **Check watcher status** - Verify SCSS watcher is running
2. **Start watcher if needed** - Use `./watch-s03.sh` or `npm run scss`
3. **Locate the correct file** - Understand the structure and find the right SCSS file
4. **Follow coding style** - Use CSS variables, BEM naming, responsive mixins
5. **Make changes** - Edit SCSS files following the guidelines
6. **Verify compilation** - Check that CSS files are updated (timestamp check)
7. **Test with DevTools** - Use MCP to navigate, evaluate, and screenshot
8. **Verify on page** - Confirm styles are applied correctly

## Common Issues

### Styles Not Applying

1. **Check watcher is running** - `ps aux | grep scss`
2. **Check compilation** - Look at CSS file modification time
3. **Check correct CSS file is loaded** - Use DevTools to see which CSS files are loaded on the page
4. **Check specificity** - Your styles might be overridden by more specific selectors
5. **Clear cache** - Browser or WordPress cache might need clearing

### Wrong File Being Modified

- Child themes may override parent theme styles
- Check if the page is using `theme-nzz.css`, `new-theme-nzz.css`, or other variants
- Use browser DevTools to find which stylesheet contains the styles you need to modify

## Example: Adding New Component Styles

```scss
// File: scss/s03-components/_custom-component.scss

:root {
  --s03-custom-component-bg: #fff;
  --s03-custom-component-padding: 2rem;
  --s03-custom-component-gap: #{$grid-gutter-width};
}

.s03-custom-component {
  padding: var(--s03-custom-component-padding);
  background-color: var(--s03-custom-component-bg);

  @include media-breakpoint-up(md) {
    padding: calc(var(--s03-custom-component-padding) * 1.5);
  }
}

.s03-custom-component__grid {
  display: grid;
  gap: var(--s03-custom-component-gap);

  @include media-breakpoint-up(lg) {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

.s03-custom-component__item {
  // Item styles

  &:hover {
    // Hover state
  }

  &:focus-visible {
    outline: 2px solid var(--s03-focus-color);
    outline-offset: 2px;
  }
}
```

Then import in the main file (e.g., `editor.scss`):

```scss
@import "s03-components/custom-component";
```

---

**Remember:** Always adapt to the existing code style in the files you're modifying. If the file uses a different pattern, follow that pattern for consistency.