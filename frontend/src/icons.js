/**
 * Centrální registr ikon pro celou aplikaci.
 *
 * Všechny ikony importujeme a re-exportujeme z jednoho místa.
 * Díky tomu:
 *   - tree-shaking zahrne jen použité ikony
 *   - stejný význam = stejná ikona všude (konzistence)
 *   - přejmenování/výměna ikony na jednom místě
 *
 * Pojmenování: icon{Význam} — ne podle vzhledu, ale podle účelu.
 */

import {
  faPlus,
  faPen,
  faTrash,
  faFloppyDisk,
  faXmark,
  faCheck,
  faMagnifyingGlass,
  faFilter,
  faArrowsRotate,
  faChevronLeft,
  faChevronRight,
  faChevronDown,
  faChevronUp,
  faEllipsisVertical,
  faArrowRightFromBracket,
  faGear,
  faGears,
  faCalculator,
  faUser,
  faUsers,
  faBuilding,
  faFileInvoiceDollar,
  faFileInvoice,
  faFileLines,
  faBoxesStacked,
  faTag,
  faTags,
  faFolderOpen,
  faTable,
  faBars,
  faAnglesLeft,
  faAnglesRight,
  faCircleNotch,
  faTriangleExclamation,
  faCircleInfo,
  faCircleCheck,
  faCircleQuestion,
  faPaperclip,
  faDownload,
  faCloudArrowDown,
  faUpload,
  faFile,
  faFilePdf,
  faFileImage,
  faImage,
  faFileWord,
  faFileExcel,
  faFileZipper,
  faEnvelope,
  faRulerCombined,
  faCube,
  faCalendarDays,
  faBook,
  faListCheck,
  faPercent,
  faWallet,
  faBuildingColumns,
  faHashtag,
  faSun,
  faMoon,
  faPalette,
  faBell,
  faGauge,
  faRobot,
  faComments,
  faLock,
  faArrowUpRightFromSquare,
} from '@fortawesome/free-solid-svg-icons';

// ── Akce (toolbary, tlačítka) ──────────────────────────────────
export const iconAdd = faPlus;
export const iconEdit = faPen;
export const iconDelete = faTrash;
export const iconSave = faFloppyDisk;
export const iconCancel = faXmark;
export const iconConfirm = faCheck;
export const iconSearch = faMagnifyingGlass;
export const iconFilter = faFilter;
export const iconRefresh = faArrowsRotate;

// ── Navigace ────────────────────────────────────────────────────
export const iconChevronLeft = faChevronLeft;
export const iconChevronRight = faChevronRight;
export const iconChevronDown = faChevronDown;
export const iconChevronUp = faChevronUp;
export const iconMenu = faBars;
export const iconClose = faXmark; // ✕ — zavření drawera / panelu (význam „zavřít", odlišný od iconCancel)
export const iconCollapse = faAnglesLeft;
export const iconExpand = faAnglesRight;
export const iconMore = faEllipsisVertical;
export const iconOpenExternal = faArrowUpRightFromSquare; // ⧉ — otevřít v plném zobrazení (chat panel → sekce Chat)

// ── Auth / uživatelé ────────────────────────────────────────────
export const iconLogout = faArrowRightFromBracket;
export const iconSettings = faGear;
export const iconAppSettings = faGears;
export const iconCalculator = faCalculator;
export const iconUser = faUser;
export const iconUsers = faUsers;
export const iconLock = faLock;

// ── Číselníky / moduly (sidebar, navigace) ──────────────────────
export const iconCompany = faBuilding;
export const iconInvoice = faFileInvoiceDollar;
export const iconInvoiceIn = faFileInvoice;
export const iconDocAccounting = faFileInvoiceDollar;
export const iconDocument = faFileLines;
export const iconWarehouse = faBoxesStacked;
export const iconTag = faTag;
export const iconTags = faTags;
export const iconFolder = faFolderOpen;
export const iconTable = faTable;
export const iconMail = faEnvelope;
export const iconRuler = faRulerCombined;
export const iconBox = faCube;
export const iconCalendar = faCalendarDays;
export const iconBook = faBook;
export const iconListCheck = faListCheck;
export const iconVat = faPercent;
export const iconWallet = faWallet;
export const iconBank = faBuildingColumns;
export const iconHash = faHashtag;

// ── Vzhled / theme ─────────────────────────────────────────────
export const iconThemeLight = faSun;
export const iconThemeDark = faMoon;
export const iconPalette = faPalette;

// ── Stav / feedback ─────────────────────────────────────────────
export const iconSpinner = faCircleNotch;
export const iconWarning = faTriangleExclamation;
export const iconInfo = faCircleInfo;
export const iconSuccess = faCircleCheck;
export const iconQuestion = faCircleQuestion;
export const iconAlert = faBell;

// ── Dashboard ──────────────────────────────────────────────────
export const iconDashboard = faGauge;
export const iconRobot = faRobot;
export const iconChat = faComments;

// ── Přílohy / soubory ─────────────────────────────────────────────
export const iconAttachment = faPaperclip;
export const iconDownload = faDownload;
export const iconCloudDownload = faCloudArrowDown;
export const iconUpload = faUpload;
export const iconFile = faFile;
export const iconFilePdf = faFilePdf;
export const iconFileImage = faFileImage;
export const iconImage = faImage; // obrázkový placeholder (branding sloty)
export const iconFileWord = faFileWord;
export const iconFileExcel = faFileExcel;
export const iconFileZip = faFileZipper;

/**
 * Mapování názvů ikon z API (string) → ikonový objekt.
 * Server může v navigaci poslat "icon": "users" a klient ho přeloží.
 * Fallback: iconTable.
 */
export const iconMap = {
  'add': iconAdd,
  'edit': iconEdit,
  'delete': iconDelete,
  'save': iconSave,
  'search': iconSearch,
  'filter': iconFilter,
  'refresh': iconRefresh,
  'settings': iconSettings,
  'user': iconUser,
  'users': iconUsers,
  'company': iconCompany,
  'invoice': iconInvoice,
  'invoice-in': iconInvoiceIn,
  'doc-accounting': iconDocAccounting,
  'document': iconDocument,
  'warehouse': iconWarehouse,
  'tag': iconTag,
  'tags': iconTags,
  'folder': iconFolder,
  'table': iconTable,
  'mail': iconMail,
  'ruler': iconRuler,
  'box': iconBox,
  'calendar': iconCalendar,
  'book': iconBook,
  'list-check': iconListCheck,
  'vat': iconVat,
  'wallet': iconWallet,
  'bank': iconBank,
  'hash': iconHash,
  'logout': iconLogout,
  'lock': iconLock,
  'calculator': iconCalculator,
  'app-settings': iconAppSettings,
  'alert': iconAlert,
  'dashboard': iconDashboard,
  'robot': iconRobot,
  'chat': iconChat,
  'cloud-download': iconCloudDownload,
  // Feed karty (kind → stavová ikona)
  'check': iconSuccess,
  'question': iconQuestion,
  'warning': iconWarning,
  'info': iconInfo,
};

/**
 * Resolve ikony podle stringu z API.
 * @param {string|undefined} name — název ikony
 * @param {object} fallback — výchozí ikona (default: iconTable)
 * @returns {object} FontAwesome icon definition
 */
export function resolveIcon(name, fallback = iconTable) {
  if (!name) return fallback;
  return iconMap[name] ?? fallback;
}
