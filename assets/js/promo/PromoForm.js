import { uploadWithProgress } from './UploadService.js';

/**
 * @param {HTMLElement} root
 */
export function initPromoForm(root) {
  if (!root) return;
  const selectType = root.querySelector('select[data-promo-type]');
  const typeGroup = root.querySelector('[data-promo-type-group]');
  const typeRadios = typeGroup ? Array.from(typeGroup.querySelectorAll('input[name="content_type"]')) : [];
  const panels = root.querySelectorAll('[data-promo-panel]');
  const imgInput = root.querySelector('[data-promo-image-input]');
  const imgPrev = root.querySelector('[data-promo-image-preview]');
  const vidFile = root.querySelector('[data-promo-video-file]');
  const vidUrl = root.querySelector('[data-promo-video-url]');
  const videoSource = root.querySelectorAll('input[name="video_source"]');
  const thumbInput = root.querySelector('[data-promo-thumb-input]');
  const thumbPrev = root.querySelector('[data-promo-thumb-preview]');
  const ytPrev = root.querySelector('[data-promo-yt-preview]');
  const durInput = root.querySelector('[data-promo-video-duration]');
  const form = root.querySelector('form[data-promo-form]');
  const bar = root.querySelector('[data-upload-progress]');
  const barFill = root.querySelector('[data-upload-progress-fill]');
  const xhrFlag = root.querySelector('[data-promo-xhr-flag]');

  root.querySelectorAll('[data-promo-dropzone]').forEach((zone) => {
    const input = zone.querySelector('[data-promo-drop-input]');
    if (!input) return;
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach((ev) => {
      zone.addEventListener(ev, (e) => {
        e.preventDefault();
        e.stopPropagation();
      });
    });
    zone.addEventListener('dragenter', () => zone.classList.add('qb-promo-dropzone--active'));
    zone.addEventListener('dragover', () => zone.classList.add('qb-promo-dropzone--active'));
    zone.addEventListener('dragleave', () => zone.classList.remove('qb-promo-dropzone--active'));
    zone.addEventListener('drop', (e) => {
      zone.classList.remove('qb-promo-dropzone--active');
      const dt = e.dataTransfer;
      if (!dt || !dt.files || !dt.files.length) return;
      input.files = dt.files;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });

  function setPanel(type) {
    panels.forEach((p) => {
      p.hidden = p.getAttribute('data-promo-panel') !== type;
    });
  }

  function activeVideoSource() {
    const on = Array.from(videoSource).find((r) => r.checked);
    return on ? on.value : 'upload';
  }

  function getContentType() {
    if (selectType) return selectType.value || 'text';
    const hit = typeRadios.find((r) => r.checked);
    return hit ? hit.value : 'text';
  }

  function syncVideoSourceUI() {
    const src = activeVideoSource();
    const fileWrap = vidFile?.closest('.form-group');
    const urlWrap = vidUrl?.closest('.form-group');
    if (fileWrap) fileWrap.hidden = src === 'url';
    if (urlWrap) urlWrap.hidden = src !== 'url';
    const ct = getContentType();
    if (vidFile) vidFile.required = src !== 'url' && ct === 'video';
    if (vidUrl) vidUrl.required = src === 'url' && ct === 'video';
  }

  function clearDuration() {
    if (durInput) durInput.value = '';
  }

  function setDurationFromFile(file) {
    clearDuration();
    if (!file || file.type !== 'video/mp4') return;
    const v = document.createElement('video');
    v.preload = 'metadata';
    const url = URL.createObjectURL(file);
    v.src = url;
    v.onloadedmetadata = () => {
      const s = Math.round(v.duration || 0);
      if (durInput && s > 0 && s <= 7200) durInput.value = String(s);
      URL.revokeObjectURL(url);
    };
    v.onerror = () => {
      URL.revokeObjectURL(url);
    };
  }

  function tryDurationFromMp4Url(u) {
    clearDuration();
    const s = String(u || '').trim();
    if (!s || !/\.mp4(\?|$)/i.test(s)) return;
    const v = document.createElement('video');
    v.preload = 'metadata';
    v.crossOrigin = 'anonymous';
    v.src = s;
    v.onloadedmetadata = () => {
      const sec = Math.round(v.duration || 0);
      if (durInput && sec > 0 && sec <= 7200) durInput.value = String(sec);
    };
    v.onerror = () => {};
  }

  function onContentTypeChange() {
    const t = getContentType();
    setPanel(t || 'text');
    syncVideoSourceUI();
    if (t !== 'video') clearDuration();

    const textHint = root.querySelector('[data-promo-text-hint]');
    const overlayHint = root.querySelector('[data-promo-overlay-hint]');
    if (textHint) textHint.hidden = (t !== 'text');
    if (overlayHint) overlayHint.hidden = (t === 'text');
  }
  selectType?.addEventListener('change', onContentTypeChange);
  typeRadios.forEach((r) => r.addEventListener('change', onContentTypeChange));
  setPanel(getContentType() || 'text');
  syncVideoSourceUI();
  videoSource.forEach((r) => r.addEventListener('change', syncVideoSourceUI));

  imgInput?.addEventListener('change', () => {
    const f = imgInput.files && imgInput.files[0];
    if (!f || !imgPrev) return;
    const url = URL.createObjectURL(f);
    imgPrev.innerHTML = `<img src="${url}" alt="" class="qb-promo-form__preview-img"/>`;
  });

  thumbInput?.addEventListener('change', () => {
    const f = thumbInput.files && thumbInput.files[0];
    if (!f || !thumbPrev) return;
    const url = URL.createObjectURL(f);
    thumbPrev.innerHTML = `<img src="${url}" alt="" class="qb-promo-form__preview-img"/>`;
  });

  function youtubeId(u) {
    const str = String(u || '').trim();
    const m = str.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/);
    return m ? m[1] : null;
  }

  function refreshYtThumb() {
    const id = youtubeId(vidUrl?.value || '');
    if (!ytPrev) return;
    if (!id) {
      ytPrev.innerHTML = '';
      return;
    }
    ytPrev.innerHTML = `<img src="https://i.ytimg.com/vi/${id}/hqdefault.jpg" alt="" class="qb-promo-form__preview-img"/>`;
  }
  vidUrl?.addEventListener('input', () => {
    refreshYtThumb();
    if (youtubeId(vidUrl?.value || '')) clearDuration();
    else tryDurationFromMp4Url(vidUrl?.value || '');
  });
  refreshYtThumb();

  vidFile?.addEventListener('change', () => {
    const f = vidFile.files && vidFile.files[0];
    if (!f) {
      clearDuration();
      return;
    }
    if (f.type !== 'video/mp4') {
      if (ytPrev) ytPrev.innerHTML = '<div class="text-xs text-danger">Use MP4 video only.</div>';
      vidFile.value = '';
      clearDuration();
      return;
    }
    if (ytPrev) {
      const url = URL.createObjectURL(f);
      ytPrev.innerHTML = `<video src="${url}" muted class="qb-promo-form__preview-img" style="max-height:200px"></video>`;
    }
    setDurationFromFile(f);
  });

  const prevTitle = root.querySelector('[data-preview-title]');
  const prevDesc = root.querySelector('[data-preview-desc]');
  const titleIn = root.querySelector('#promo-title');
  const descIn = root.querySelector('#promo-desc');
  function syncLivePreview() {
    if (prevTitle && titleIn) prevTitle.textContent = titleIn.value.trim() || 'Your title';
    if (prevDesc && descIn) {
      const t = descIn.value.trim();
      prevDesc.textContent =
        t.length > 120 ? t.slice(0, 117) + '…' : t || 'Description or overlay text appears here.';
    }
  }
  titleIn?.addEventListener('input', syncLivePreview);
  descIn?.addEventListener('input', syncLivePreview);

  form?.addEventListener('submit', async (e) => {
    if (!xhrFlag || xhrFlag.value !== '1') return;
    e.preventDefault();
    if (getContentType() === 'video') {
      const src = activeVideoSource();
      if (src === 'url' && !(vidUrl?.value || '').trim()) {
        const box = root.querySelector('[data-promo-feedback]');
        if (box) {
          box.hidden = false;
          box.className = 'alert alert-danger mt-3';
          box.textContent = 'Please paste a YouTube or MP4 URL.';
        }
        return;
      }
      if (src !== 'url' && !(vidFile?.files && vidFile.files[0])) {
        const box = root.querySelector('[data-promo-feedback]');
        if (box) {
          box.hidden = false;
          box.className = 'alert alert-danger mt-3';
          box.textContent = 'Please select an MP4 file.';
        }
        return;
      }
    }
    const fd = new FormData(form);
    if (bar) bar.hidden = false;
    if (barFill) barFill.style.width = '0%';
    const res = await uploadWithProgress(form.action || window.location.pathname, fd, (pct) => {
      if (barFill) barFill.style.width = `${pct}%`;
    });
    const box = root.querySelector('[data-promo-feedback]');
    if (box) {
      box.hidden = false;
      box.className = 'alert ' + (res.ok ? 'alert-success' : 'alert-danger') + ' mt-3';
      box.textContent = res.ok ? (res.json && res.json.message) || 'Saved.' : res.error || 'Error';
    }
    if (res.ok && res.json && res.json.redirect) {
      window.location.href = res.json.redirect;
    }
  });
}
