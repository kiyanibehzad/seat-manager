import {APP_NAME, APP_TAGLINE, APP_VERSION} from './brand.js';

const externalIcon = '<svg class="site-footer-external" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M9 3h4v4M13 3 7 9M7 3H4a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V9"/></svg>';
const link = (href, label, description=label) => `<a class="site-footer-link" href="${href}" target="_blank" rel="noopener noreferrer" aria-label="${description}؛ بازشدن در برگهٔ جدید">${label}${externalIcon}</a>`;

export function footerMarkup() {
  const year = new Intl.DateTimeFormat('fa-IR', {calendar:'persian', timeZone:'Asia/Tehran', year:'numeric'}).format(new Date());
  const version = APP_VERSION.replace(/\d/g, digit=>'۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
  return `<footer class="app-footer" aria-label="پاورقی برنامه">
    <div class="site-footer-main">
      <div class="site-footer-identity"><bdi class="site-footer-name" dir="ltr">${APP_NAME}</bdi><span>${APP_TAGLINE}</span></div>
      <nav class="site-footer-links" aria-label="پیوندهای پروژه">${link('https://github.com/kiyanibehzad/seat-manager','کد منبع در <bdi dir="ltr">GitHub</bdi>','کد منبع Seat Manager در GitHub')}</nav>
    </div>
    <div class="site-footer-bottom">
      <p class="site-footer-credit">ساختهٔ ${link('https://github.com/kiyanibehzad','بهزاد کیانی')} برای ${link('https://www.netafraz.com/','نت‌افراز')}، با کمک <bdi dir="ltr">Codex</bdi></p>
      <div class="site-footer-meta"><span>© <span data-footer-year>${year}</span> <bdi dir="ltr">${APP_NAME}</bdi></span><span>نسخهٔ <bdi dir="ltr">${version}</bdi></span><span>زمان‌ها به وقت ایران</span></div>
    </div>
  </footer>`;
}
