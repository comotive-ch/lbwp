# LBWP Project Instructions

## Framework Context

This is a WordPress project using the **LBWP framework** plugin located at `wp-content/plugins/lbwp`.

## Mandatory: Coding Guidelines

**You MUST always follow the LBWP coding guidelines** defined in `.claude/skills/coding-guidelines.md`.

Key requirements for ALL code you write:

1. ✅ **Complete PHPDoc headers** for every function/method
2. ✅ **Small, focused functions** - one responsibility per function
3. ✅ **Separation of concerns** - create new classes rather than mixing responsibilities
4. ✅ **English only** - all code, comments, and documentation in English
5. ✅ **Modern PHP syntax** - use `[]` for arrays, short array syntax
6. ✅ **Proper namespacing** - follow `LBWP\[Category]\[SubCategory]` structure

## Before Writing Any Code

1. Check if functionality already exists in the LBWP framework
2. Determine if a new class is needed or if existing code should be extended
3. Plan the implementation with proper separation of concerns
4. Ensure all functions will have complete documentation

## Framework Structure Awareness

The LBWP framework provides:
- **Utilities**: `LBWP\Util\*` (WordPress, Strings, Date, ArrayManipulation, File)
- **Helpers**: `LBWP\Helper\*` (Metabox, etc.)
- **Components**: `LBWP\Theme\Component\*`
- **Features**: `LBWP\Theme\Feature\*`
- **Modules**: `LBWP\Module\*`

Always check these namespaces for existing functionality before creating new code.

## Code Review Checklist

Before completing any task, verify:
- [ ] All functions have complete PHPDoc headers (`@param`, `@return`, description)
- [ ] Functions are small and focused (< 50 lines ideally)
- [ ] Proper separation of concerns
- [ ] English language used throughout
- [ ] Modern PHP syntax (short array syntax, etc.)
- [ ] No deep nesting (use early returns)
- [ ] Security considerations addressed (sanitization, escaping, prepared statements)