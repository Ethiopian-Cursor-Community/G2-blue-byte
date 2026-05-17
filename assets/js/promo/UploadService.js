/**
 * XMLHttpRequest upload with progress (PromoForm / other multipart posts).
 * @param {string} url
 * @param {FormData} formData
 * @param {(pct: number) => void} [onProgress]
 * @returns {Promise<{ok: boolean, json?: object, error?: string}>}
 */
export function uploadWithProgress(url, formData, onProgress) {
  return new Promise((resolve) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.responseType = 'json';
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable && typeof onProgress === 'function') {
        onProgress(Math.round((e.loaded / e.total) * 100));
      }
    };
    xhr.onload = () => {
      let json = xhr.response;
      if (typeof json === 'string') {
        try {
          json = JSON.parse(json);
        } catch {
          json = null;
        }
      }
      if (xhr.status >= 200 && xhr.status < 300 && json && json.success) {
        resolve({ ok: true, json });
      } else {
        const err = (json && json.error) || xhr.statusText || 'Upload failed';
        resolve({ ok: false, error: String(err), json });
      }
    };
    xhr.onerror = () => resolve({ ok: false, error: 'Network error' });
    xhr.send(formData);
  });
}
