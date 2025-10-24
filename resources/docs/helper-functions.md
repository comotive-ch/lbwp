# LBWP Helper Classes

## Converter
`LBWP\Helper\Converter` - Document and media conversion utilities
- `initialize()` - Init CloudConvert API
- `convert($file, $from, $to)` - Convert files between formats
- `forceNonWebpImageUrl($url): string` - Convert WebP to JPG
- `htmlToDoc($content, $name, $lang, $return): void|string` - HTML to DOCX via Pandoc
- `htmlToPdf($content, $name, $styles): void` - HTML to PDF via WeasyPrint
- `docxToPdf($doc, $name, $return): void|string` - DOCX to PDF
- `excelToArray($file): array` - Excel to array

## Metabox
`LBWP\Helper\Metabox` - WordPress metabox management
- `get($postType): Metabox` - Get metabox instance
- `getGroupIdList($postId, $prefix, $fallback): array` - Get group IDs with fallback
- `ajaxAssignPostsData($types): void` - AJAX post assignment autocomplete

## PageSettings
`LBWP\Helper\PageSettings` - WordPress admin page settings
- `initialize(): void` - Init settings system
- `getBackend(): PageSettingsBackend` - Get backend instance
- `addPage($slug, $name, $parent, $capability): string` - Add admin page
- `addSection($slug, $pageSlug, $title, $description): string` - Add settings section
- `addItem($item): string` - Add settings item
- `addCallback($page, $section, $key, $title, $displayCallback, $saveCallback, $isMultilang): void` - Add custom field
- `addFileUpload($page, $section, $key, $title, $config, $isMultilang): void` - Add file upload
- `addTextInput($page, $section, $key, $title, $isMultilang, $description, $config): void` - Add text input
- `addCheckbox($page, $section, $key, $titleRight, $titleLeft, $isMultilang, $description, $config): void` - Add checkbox
- `addDropdown($page, $section, $key, $title, $values, $isMultilang, $description, $config): void` - Add dropdown
- `addTypeDropdown($page, $section, $key, $title, $description, $language, $types): void` - Add post type dropdown
- `addGroupDropdown($page, $section, $key, $title, $values, $isMultilang, $description, $config): void` - Add grouped dropdown
- `addNumber($page, $section, $key, $title, $isMultilang, $description, $config): void` - Add number field
- `addTextarea($page, $section, $key, $title, $isMultilang, $description, $config): void` - Add textarea
- `addEditor($page, $section, $key, $title, $isMultilang, $description, $config): void` - Add WYSIWYG editor
- `get($id): mixed` - Get setting value
- `_e($id): void` - Echo setting value
- `getConfiguration(): array` - Get full config

## ZipDistance
`LBWP\Helper\ZipDistance` - Swiss postal code utilities
- `getNearestByLatLng($lat, $lng, $count): array` - Get nearest by coordinates
- `getNearest($zip, $count): array` - Get nearest postal codes
- `getDistance($from, $to): int` - Distance between zip codes (meters)
- `calculateDistance($latFrom, $lonFrom, $latTo, $lonTo, $earthRadius): float` - Geographic distance
- `getZipList($field): array` - Get zip list with field
- `getZipCantonMap(): array` - Zip to canton mapping
- `getCantonZipMap(): array` - Canton to zip codes
- `getCantonList(): array` - Swiss cantons list

## WpMenu
`LBWP\Helper\WpMenu` - WordPress navigation menu utilities
- `getNavigationByLevel($navHtml, $args): mixed` - Extract nav by level
- `extractNavigationByXpath($navHtml, $query, $removeSubTreeQuery): mixed` - Extract nav by XPath
- `getCurrentTopParentId(): int` - Get current top parent menu ID
- `makeItemAncestorsCurrentRecursive($items, $index): array` - Mark ancestors as current
- `getTreeFromItems($items, $levels, $cacheKey): array` - Build menu tree
- `maybeFixCurrentItemClass(&$item): void` - Fix current item CSS classes

## Tracking\MicroData
`LBWP\Helper\Tracking\MicroData` - Structured data/microdata
- `printArticleData($post): void` - Print JSON-LD article data
- `addLogoUrl(&$data, $field): void` - Add logo to structured data
- `printPageData($post): void` - Print JSON-LD page data
- `addImageObject($post, &$data): void` - Add image to structured data
- `printEventData($event): void` - Print JSON-LD event data
- `printJsonLdOject($data): void` - Print JSON-LD structured data