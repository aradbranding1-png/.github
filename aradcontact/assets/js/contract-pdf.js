/**
 * ساختِ PDFِ اسنادِ قرارداد در مرورگر — دقیقاً مثلِ نسخه‌ی چاپی (پیوستِ تیکتِ آراد برندینگ).
 *
 * قرارداد: هر صفحه A4 با سربرگِ کامل، تاریخ/شماره/پیوست بالای صفحه و ردیفِ امضا پایینِ صفحه
 *   (همان چیدمانِ @media print در ctr_doc_render). متن بین سربرگ و پاورقی قرار می‌گیرد و
 *   صفحه‌ها فقط در فاصله‌ی بینِ سطرها بریده می‌شوند (هیچ سطری نصفه نمی‌شود).
 * پیش‌فاکتور / شرحِ خدمات: برگه با حاشیه‌ی ۸ میلی‌متری روی A4، با همان برشِ بینِ سطرها.
 *
 *   ctrMakePdf('contract_print.php?id=12&doc=contract')  →  Promise<Blob>
 */
(function () {
  const CDN = [
    ['html2canvas', 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js'],
    ['jspdf', 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js'],
  ];
  const SCALE = 2;
  const PX_PER_MM = 96 / 25.4;
  const A4W = 210, A4H = 297;
  // چیدمانِ چاپیِ قرارداد (میلی‌متر) — هم‌خوان با CSSِ چاپ در ctr_doc_render
  const CTR_TOP = 58, CTR_BOTTOM = 76;

  function loadScript(src) {
    return new Promise((ok, fail) => {
      const s = document.createElement('script');
      s.src = src;
      s.onload = () => ok();
      s.onerror = () => fail(new Error('کتابخانه‌ی PDF بارگذاری نشد'));
      document.head.appendChild(s);
    });
  }
  async function loadLibs() {
    for (const [name, src] of CDN) {
      if (name === 'html2canvas' && window.html2canvas) continue;
      if (name === 'jspdf' && window.jspdf) continue;
      await loadScript(src);
    }
  }
  function openFrame(url) {
    return new Promise((ok, fail) => {
      const f = document.createElement('iframe');
      // پهنای بیشتر از ۸۲۰ پیکسل تا چیدمانِ موبایل فعال نشود؛ برگه خودش ۲۱۰ میلی‌متر است
      f.style.cssText = 'position:fixed;left:-12000px;top:0;width:1100px;height:1500px;border:0';
      f.onload = () => ok(f);
      f.onerror = () => fail(new Error('صفحه‌ی سند باز نشد'));
      f.src = url;
      document.body.appendChild(f);
    });
  }
  async function settle(d) {
    if (d.fonts && d.fonts.ready) { try { await d.fonts.ready; } catch (e) {} }
    await Promise.all(Array.from(d.images).map(img => img.complete ? null : new Promise(r => { img.onload = img.onerror = r; })));
    await new Promise(r => setTimeout(r, 300));
  }
  function shoot(el, transparent) {
    return window.html2canvas(el, {
      scale: SCALE, useCORS: true, logging: false,
      backgroundColor: transparent ? null : '#ffffff',
      scrollX: 0, scrollY: 0,
      windowWidth: el.ownerDocument.documentElement.clientWidth,
    });
  }
  /** آیا ردیفِ y از بوم خالی است (بدونِ متن)؟ */
  function blankRow(ctx, w, y) {
    const px = ctx.getImageData(0, y, w, 1).data;
    for (let i = 0; i < px.length; i += 4) {
      if (px[i + 3] > 24 && (px[i] < 225 || px[i + 1] < 225 || px[i + 2] < 225)) return false;
    }
    return true;
  }
  /** نقطه‌های برش: هر صفحه حداکثر pageH پیکسل؛ برش روی ردیفِ خالیِ بینِ سطرها، یا شکستِ صفحه‌ی اجباری */
  function cuts(canvas, pageH, forced) {
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    const out = [];
    let y = 0;
    const maxBack = Math.round(pageH * 0.25);
    while (y < canvas.height) {
      let end = Math.min(y + pageH, canvas.height);
      const f = forced.find(b => b > y + 4 && b < end);
      if (f) {
        end = f;
      } else if (end < canvas.height) {
        let e = end;
        while (e > end - maxBack && !blankRow(ctx, canvas.width, e)) e--;
        if (e > end - maxBack) end = e;
      }
      out.push([y, end]);
      y = end;
      // ردیف‌های خالیِ ابتدای صفحه‌ی بعد حذف شوند
      while (y < canvas.height && out.length && blankRow(ctx, canvas.width, y) && y - end < 40 * SCALE) y++;
    }
    return out;
  }
  function pageCanvas() {
    const c = document.createElement('canvas');
    c.width = Math.round(A4W * PX_PER_MM * SCALE);
    c.height = Math.round(A4H * PX_PER_MM * SCALE);
    const g = c.getContext('2d');
    g.fillStyle = '#ffffff';
    g.fillRect(0, 0, c.width, c.height);
    return [c, g];
  }
  function toPdf(pages) {
    const pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait', compress: true });
    pages.forEach((c, i) => {
      if (i) pdf.addPage();
      pdf.addImage(c.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, A4W, A4H, undefined, 'FAST');
    });
    return pdf.output('blob');
  }

  /** قرارداد روی سربرگ */
  async function contractPdf(d) {
    const mm = PX_PER_MM;
    const content = d.querySelector('.paper .content');
    // ۱) قابِ ثابتِ هر صفحه: سربرگِ کامل + تاریخ/شماره/پیوست + ردیفِ امضا
    const frame = d.createElement('div');
    frame.style.cssText = 'position:absolute;left:0;top:0;width:210mm;height:297mm;overflow:hidden;background:#fff;direction:rtl;z-index:99999';
    const bg = d.querySelector('.fixed-bg img');
    if (bg) {
      const img = d.createElement('img');
      img.src = bg.src;
      img.style.cssText = 'position:absolute;left:0;top:0;width:210mm;height:297mm';
      frame.appendChild(img);
      if (!img.complete) await new Promise(r => { img.onload = img.onerror = r; });
    }
    const hv = d.querySelector('.fixed-hv');
    if (hv) {
      const c = hv.cloneNode(true);
      c.style.cssText = 'display:block;position:absolute;top:0;left:0;width:210mm;height:60mm';
      frame.appendChild(c);
    }
    const sig = d.querySelector('.fixed-sig');
    if (sig) {
      const c = sig.cloneNode(true);
      c.style.cssText = 'display:flex;position:absolute;top:228.5mm;left:22mm;width:170mm;justify-content:space-between;font-weight:800;font-size:10.5pt';
      frame.appendChild(c);
    }
    d.body.appendChild(frame);
    const frameCanvas = await shoot(frame, false);
    frame.remove();

    // ۲) متن (پس‌زمینه‌ی شفاف تا سربرگ زیرش دیده شود؛ مثلِ چاپ)
    const paper = d.querySelector('.paper');
    paper.style.boxShadow = 'none';
    paper.style.background = 'transparent';
    paper.style.margin = '0';
    content.style.background = 'transparent';
    const textCanvas = await shoot(content, true);
    const top = content.getBoundingClientRect().top;
    const forced = Array.from(content.querySelectorAll('.ctr-pb')).map(el => Math.round((el.getBoundingClientRect().bottom - top) * SCALE))
      .concat(Array.from(content.querySelectorAll('.ctr-pb-before')).map(el => Math.round((el.getBoundingClientRect().top - top) * SCALE)))
      .sort((a, b) => a - b);
    const pageH = Math.floor((A4H - CTR_TOP - CTR_BOTTOM) * mm * SCALE);
    const pages = cuts(textCanvas, pageH, forced).map(([y0, y1]) => {
      const [c, g] = pageCanvas();
      g.drawImage(frameCanvas, 0, 0, c.width, c.height);
      const h = y1 - y0;
      const dx = Math.round((c.width - textCanvas.width) / 2);
      g.drawImage(textCanvas, 0, y0, textCanvas.width, h, dx, Math.round(CTR_TOP * mm * SCALE), textCanvas.width, h);
      return c;
    });
    return toPdf(pages);
  }

  /** پیش‌فاکتور / شرحِ خدمات */
  async function sheetPdf(d) {
    const el = d.querySelector('.sheet') || d.body;
    el.style.boxShadow = 'none';
    el.style.borderRadius = '0';
    const canvas = await shoot(el, false);
    const margin = 8;
    const [probe] = pageCanvas();
    const fit = (probe.width - 2 * margin * PX_PER_MM * SCALE) / canvas.width; // بوم ← عرضِ صفحه
    const pageH = Math.floor((probe.height - 2 * margin * PX_PER_MM * SCALE) / fit);
    const pages = cuts(canvas, pageH, []).map(([y0, y1]) => {
      const [c, g] = pageCanvas();
      const m = margin * PX_PER_MM * SCALE;
      g.drawImage(canvas, 0, y0, canvas.width, y1 - y0, m, m, canvas.width * fit, (y1 - y0) * fit);
      return c;
    });
    return toPdf(pages);
  }

  window.ctrMakePdf = async function (url) {
    await loadLibs();
    const f = await openFrame(url);
    try {
      const d = f.contentDocument;
      d.querySelectorAll('.toolbar, .no-print').forEach(n => n.remove());
      await settle(d);
      // فاصله‌ی حروف (letter-spacing) حروفِ فارسی را در تصویر از هم جدا می‌کند؛ برای متنِ فارسی خنثی می‌شود
      d.querySelectorAll('body *').forEach(n => {
        const ls = d.defaultView.getComputedStyle(n).letterSpacing;
        if (ls && ls !== 'normal' && ls !== '0px' && /[\u0600-\u06FF]/.test(n.textContent || '')) n.style.letterSpacing = 'normal';
      });
      return d.querySelector('.paper .content') ? await contractPdf(d) : await sheetPdf(d);
    } finally {
      f.remove();
    }
  };
})();
