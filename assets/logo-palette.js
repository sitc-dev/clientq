(function() {
    async function extract(file) {
        const image = await createImageBitmap(file);
        const canvas = document.createElement('canvas');
        canvas.width = 96;
        canvas.height = 96;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        context.clearRect(0, 0, 96, 96);

        const scale = Math.min(96 / image.width, 96 / image.height);
        const width = image.width * scale;
        const height = image.height * scale;
        context.drawImage(image, (96 - width) / 2, (96 - height) / 2, width, height);
        image.close();

        const pixels = context.getImageData(0, 0, 96, 96).data;
        const buckets = new Map();

        for (let i = 0; i < pixels.length; i += 16) {
            const alpha = pixels[i + 3];
            if (alpha < 128) continue;

            const rgb = [pixels[i], pixels[i + 1], pixels[i + 2]];
            const max = Math.max(...rgb);
            const min = Math.min(...rgb);
            if (max > 245 || max < 25 || max - min < 20) continue;

            const quantized = rgb.map(value => Math.min(255, Math.round(value / 32) * 32));
            const key = quantized.join(',');
            buckets.set(key, (buckets.get(key) || 0) + 1);
        }

        const colors = [...buckets.entries()]
            .sort((a, b) => b[1] - a[1])
            .map(([key]) => key.split(',').map(Number));

        if (!colors.length) return { primary: '#111827', accent: '#DC2626' };

        const primaryRgb = colors[0];
        const accentRgb = colors.find(color => colorDistance(primaryRgb, color) > 90)
            || rotateHue(primaryRgb, 55);

        return {
            primary: ensureWhiteContrast(primaryRgb),
            accent: ensureWhiteContrast(accentRgb)
        };
    }

    function colorDistance(first, second) {
        return Math.sqrt(first.reduce(
            (sum, value, index) => sum + Math.pow(value - second[index], 2),
            0
        ));
    }

    function rotateHue([r, g, b], degrees) {
        r /= 255;
        g /= 255;
        b /= 255;
        const max = Math.max(r, g, b);
        const min = Math.min(r, g, b);
        const lightness = (max + min) / 2;
        const delta = max - min;
        let hue = 0;
        const saturation = delta === 0 ? 0 : delta / (1 - Math.abs(2 * lightness - 1));

        if (delta) {
            if (max === r) hue = 60 * (((g - b) / delta) % 6);
            else if (max === g) hue = 60 * ((b - r) / delta + 2);
            else hue = 60 * ((r - g) / delta + 4);
        }

        hue = (hue + degrees + 360) % 360;
        const chroma = (1 - Math.abs(2 * lightness - 1)) * Math.max(saturation, 0.55);
        const x = chroma * (1 - Math.abs((hue / 60) % 2 - 1));
        const m = lightness - chroma / 2;
        let values;
        if (hue < 60) values = [chroma, x, 0];
        else if (hue < 120) values = [x, chroma, 0];
        else if (hue < 180) values = [0, chroma, x];
        else if (hue < 240) values = [0, x, chroma];
        else if (hue < 300) values = [x, 0, chroma];
        else values = [chroma, 0, x];
        return values.map(value => Math.round((value + m) * 255));
    }

    function ensureWhiteContrast(rgb) {
        const adjusted = [...rgb];
        while (contrastWithWhite(adjusted) < 4.5) {
            adjusted[0] *= 0.88;
            adjusted[1] *= 0.88;
            adjusted[2] *= 0.88;
        }
        return '#' + adjusted
            .map(value => Math.round(value).toString(16).padStart(2, '0'))
            .join('')
            .toUpperCase();
    }

    function contrastWithWhite(rgb) {
        const luminance = rgb.map(value => {
            const channel = value / 255;
            return channel <= 0.03928
                ? channel / 12.92
                : Math.pow((channel + 0.055) / 1.055, 2.4);
        });
        const relative = 0.2126 * luminance[0]
            + 0.7152 * luminance[1]
            + 0.0722 * luminance[2];
        return 1.05 / (relative + 0.05);
    }

    window.ClientQLogoPalette = { extract };
})();
