export const APP_NAME = 'Seat Manager';
export const APP_TAGLINE = 'مدیریت حساب‌ها و سیت‌ها';
export const APP_VERSION = '0.1.0';

const icon = new URL('./brand/icon.svg', import.meta.url);
const version = new URL(import.meta.url).searchParams.get('v');
if (version) icon.searchParams.set('v', version);
export const APP_ICON_URL = icon.href;

export const pageTitle = title => `${title || APP_NAME} | ${APP_NAME}`;
