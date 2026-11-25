(() => {
    function loadLogo(url) {
        return new Promise((resolve, reject) => {
            const image = new Image();
            const timer = setTimeout(() => {
                image.src = '';
                reject(new Error('Business logo loading timed out.'));
            }, 10000);
            image.onload = () => {
                clearTimeout(timer);
                resolve(image);
            };
            image.onerror = () => {
                clearTimeout(timer);
                reject(new Error('Business logo could not be loaded.'));
            };
            image.src = url;
        });
    }

    function setNameFont(context, name, width) {
        context.font = 'bold 56px sans-serif';
        const measured = context.measureText(name).width;
        if (measured > width) {
            context.font = `bold ${Math.max(24, Math.floor(56 * width / measured))}px sans-serif`;
        }
    }

    async function render({ url, name, logo, color }) {
        if (!window.QRCode) throw new Error('QR code generation is temporarily unavailable.');
        const source = document.createElement('div');
        const size = 768;
        new QRCode(source, {
            text: url, width: size, height: size, colorDark: color,
            colorLight: '#FFFFFF', correctLevel: QRCode.CorrectLevel.H
        });
        const code = source.querySelector('canvas');
        if (!code) throw new Error('This browser cannot generate a downloadable QR code.');
        let image = null;
        let logoFailed = false;
        if (logo) {
            try {
                image = await loadLogo(logo);
            } catch (error) {
                console.warn('ClientQ QR branding:', error);
                logoFailed = true;
            }
        }
        const canvas = document.createElement('canvas');
        const margin = 160;
        canvas.width = size + margin * 2;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('This browser cannot generate a downloadable QR code.');
        // Match a 6px visible gap at the standard 320px preview width.
        const gap = 6 * canvas.width / 320;
        const padding = 24;
        setNameFont(context, name, canvas.width - 96);
        const nameFont = context.font;
        const nameMetrics = context.measureText(name);
        context.font = '42px sans-serif';
        const footerMetrics = context.measureText('Powered by ClientQ');
        const codeTop = image ? margin
            : padding + nameMetrics.actualBoundingBoxAscent + nameMetrics.actualBoundingBoxDescent + gap;
        const footerBaseline = codeTop + size + gap + footerMetrics.actualBoundingBoxAscent;
        canvas.height = Math.ceil(footerBaseline + footerMetrics.actualBoundingBoxDescent + padding);
        context.fillStyle = '#FFFFFF';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(code, margin, codeTop);
        if (image) {
            // Only the logo covers QR modules; labels remain above and below.
            const badge = Math.round(size * 0.24);
            const x = (canvas.width - badge) / 2;
            const y = codeTop + (size - badge) / 2;
            context.fillRect(x, y, badge, badge);
            const scale = Math.min((badge - 24) / image.naturalWidth, (badge - 24) / image.naturalHeight);
            const imageWidth = image.naturalWidth * scale;
            const imageHeight = image.naturalHeight * scale;
            context.drawImage(image, x + (badge - imageWidth) / 2, y + (badge - imageHeight) / 2, imageWidth, imageHeight);
        }
        context.fillStyle = color;
        context.textAlign = 'center';
        context.textBaseline = 'alphabetic';
        if (!image) {
            context.font = nameFont;
            context.fillText(name, canvas.width / 2, padding + nameMetrics.actualBoundingBoxAscent, canvas.width - 96);
        }
        context.font = '42px sans-serif';
        context.fillText('Powered by ClientQ', canvas.width / 2, footerBaseline);
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', `${name} signup QR code, powered by ClientQ`);
        return { canvas, logoFailed };
    }

    window.ClientQTenantQr = { render };
})();
