# WordPress.org asset sources

The shipped assets in `../` are rendered from these files with headless Chrome —
no design tool, no binary source. Everything uses the plugin's own design tokens
(see `docs/ui.md`): brand `#2271b1`, ok `#00a32a`, danger `#d63638`, ink `#1d2327`,
and the shield/power glyphs from `FPAD_Admin_UI::icon_paths()`.

| Source | Renders |
|--------|---------|
| `icon.svg` (+ `icon.html` wrapper) | `../icon.svg`, `../icon-256x256.png`, `../icon-128x128.png` |
| `banner.html` | `../banner-1544x500.png`, `../banner-772x250.png` |

## Re-render

Run from this directory. `--force-device-scale-factor=2` is what makes the retina
banner exactly 1544x500; the smaller sizes are downsampled from it.

```bash
CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"

# Icon: 256 CSS px at 2x -> 512, downsampled to the two shipped sizes.
"$CHROME" --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=2 \
	--window-size=256,256 --virtual-time-budget=2000 \
	--screenshot=icon-render.png "file://$PWD/icon.html"
cp icon-render.png ../icon-256x256.png && sips -z 256 256 ../icon-256x256.png
cp icon-render.png ../icon-128x128.png && sips -z 128 128 ../icon-128x128.png
cp icon.svg ../icon.svg

# Banner: 772x250 CSS px at 2x -> 1544x500, downsampled to the 1x banner.
"$CHROME" --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=2 \
	--window-size=772,250 --virtual-time-budget=2500 \
	--screenshot=banner-render.png "file://$PWD/banner.html"
cp banner-render.png ../banner-1544x500.png
cp banner-render.png ../banner-772x250.png && sips -z 250 772 ../banner-772x250.png
rm icon-render.png banner-render.png
```

Fonts are the system UI stack, so re-rendering on a non-macOS machine will shift
the type. Keep the rendered PNGs committed.
