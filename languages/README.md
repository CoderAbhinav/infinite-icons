Translation files (`.pot`, `.po`, `.mo`) for Infinite Icons.

The `.pot` template is generated at release time with:

    wp i18n make-pot . languages/infinite-icons.pot --exclude=assets/src,build,node_modules,tests,vendor,packs
