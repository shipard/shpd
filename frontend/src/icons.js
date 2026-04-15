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
  faUser,
  faUsers,
  faBuilding,
  faFileInvoiceDollar,
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
  faPaperclip,
  faDownload,
  faUpload,
  faFile,
  faFilePdf,
  faFileImage,
  faFileWord,
  faFileExcel,
  faFileZipper,
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
export const iconCollapse = faAnglesLeft;
export const iconExpand = faAnglesRight;
export const iconMore = faEllipsisVertical;

// ── Auth / uživatelé ────────────────────────────────────────────
export const iconLogout = faArrowRightFromBracket;
export const iconSettings = faGear;
export const iconUser = faUser;
export const iconUsers = faUsers;

// ── Číselníky / moduly (sidebar, navigace) ──────────────────────
export const iconCompany = faBuilding;
export const iconInvoice = faFileInvoiceDollar;
export const iconDocument = faFileLines;
export const iconWarehouse = faBoxesStacked;
export const iconTag = faTag;
export const iconTags = faTags;
export const iconFolder = faFolderOpen;
export const iconTable = faTable;

// ── Stav / feedback ─────────────────────────────────────────────
export const iconSpinner = faCircleNotch;
export const iconWarning = faTriangleExclamation;
export const iconInfo = faCircleInfo;
export const iconSuccess = faCircleCheck;

// ── Přílohy / soubory ─────────────────────────────────────────────
export const iconAttachment = faPaperclip;
export const iconDownload = faDownload;
export const iconUpload = faUpload;
export const iconFile = faFile;
export const iconFilePdf = faFilePdf;
export const iconFileImage = faFileImage;
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
  'document': iconDocument,
  'warehouse': iconWarehouse,
  'tag': iconTag,
  'tags': iconTags,
  'folder': iconFolder,
  'table': iconTable,
  'logout': iconLogout,
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
