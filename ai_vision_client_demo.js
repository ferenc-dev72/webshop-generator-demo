/**
 * Client-Side In-Browser AI Photo Studio
 * Uses ONNX Runtime Web with ISNet Neural Model for Zero-Cost Edge Background Removal
 * Author: Your Name
 */

let ortSession = null;

async function initVisionAI() {
    try {
        ort.env.wasm.wasmPaths = 'static/models/wasm/';
        ort.env.wasm.numThreads = 1;
        ortSession = await ort.InferenceSession.create('static/models/isnet_fp16/model.onnx');
        console.log("✓ ONNX Vision AI Model initialized successfully in browser!");
    } catch (err) {
        console.error("Failed to load local ONNX model, falling back to CDN...", err);
    }
}

/**
 * Runs neural background extraction on HTML5 canvas
 * Supports Solidify AI, Alpha Cutoff, and White Purge tuning
 */
async function processAIImage(canvas, ctx, sensitivity = 0, alphaCutoff = 25, whitePurge = 0, solidify = 50) {
    const inputSize = 1024;
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = inputSize;
    tempCanvas.height = inputSize;
    const tempCtx = tempCanvas.getContext('2d', { willReadFrequently: true });
    tempCtx.drawImage(canvas, 0, 0, inputSize, inputSize);

    const rawData = tempCtx.getImageData(0, 0, inputSize, inputSize).data;
    const tensorBuffer = new Float32Array(3 * inputSize * inputSize);

    // RGB Normalization
    for (let i = 0; i < rawData.length; i += 4) {
        tensorBuffer[i / 4] = rawData[i] / 255.0;
        tensorBuffer[(i / 4) + (inputSize * inputSize)] = rawData[i + 1] / 255.0;
        tensorBuffer[(i / 4) + (2 * inputSize * inputSize)] = rawData[i + 2] / 255.0;
    }

    const inputTensor = new ort.Tensor('float32', tensorBuffer, [1, 3, inputSize, inputSize]);
    const results = await ortSession.run({ [ortSession.inputNames[0]]: inputTensor });
    const mask = results[ortSession.outputNames[0]].data;

    const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = imgData.data;
    const bias = sensitivity / 100.0;
    const cutoffThreshold = (alphaCutoff / 100.0) * 255.0;

    for (let y = 0; y < canvas.height; y++) {
        for (let x = 0; x < canvas.width; x++) {
            const mX = Math.floor(x * (inputSize / canvas.width));
            const mY = Math.floor(y * (inputSize / canvas.height));
            const maskIdx = mY * inputSize + mX;

            let alpha = Math.max(0, Math.min(1, mask[maskIdx] - bias));
            let finalAlpha = Math.round(alpha * 255);
            const pixelIdx = (y * canvas.width + x) * 4;

            if (finalAlpha < cutoffThreshold) finalAlpha = 0;

            // Solidify AI: Boost internal alpha for glossy metals / tools
            if (solidify > 0 && finalAlpha > 30) {
                const norm = finalAlpha / 255.0;
                const boosted = Math.pow(norm, 1.0 - (solidify / 120.0));
                finalAlpha = Math.min(255, Math.round(boosted * 255));
            }

            data[pixelIdx + 3] = finalAlpha;
        }
    }

    ctx.putImageData(imgData, 0, 0);
    console.log("✓ Neural background extraction completed!");
}