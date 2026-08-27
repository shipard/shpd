/**
 * Titulky — z časové osy do ASS.
 *
 * ASS místo SRT kvůli kontrole nad stylem: pozice, podklad a font se dají
 * popsat rovnou v souboru, takže `compose` vystačí s jedním filtrem
 * a nemusí skládat drawtext výrazy (R5).
 */

/** Minimální doba, po kterou má titulek zůstat, i kdyby byl jednoslovný. */
const MIN_SECONDS = 1.2;

/** Kolik znaků za sekundu stihne divák přečíst. */
const CHARS_PER_SECOND = 15;

/** Nad tímhle je titulek dlouhý na jeden pohled. */
const MAX_CHARS = 90;

/**
 * Font titulků. Musí být stejný na všech strojích, jinak se videa mezi
 * sebou rozejdou — proto ho INSTALL.md vyžaduje jako apt balíček.
 */
const FONT = 'Liberation Sans';

/**
 * Události `caption` na intervaly. `text: null` titulek sundá, další
 * neprázdný titulek ten předchozí vystřídá.
 *
 * @param {{events: Array<{t:number,type:string,text?:string|null}>, duration:number}} timeline
 * @returns {Array<{start:number,end:number,text:string}>}
 */
export function buildCaptions(timeline) {
  const captions = [];
  let open = null;

  for (const event of timeline.events) {
    if (event.type !== 'caption') continue;

    if (open) {
      open.end = event.t;
      captions.push(open);
      open = null;
    }
    if (event.text !== null && event.text !== undefined) {
      open = { start: event.t, end: null, text: event.text };
    }
  }

  if (open) {
    open.end = timeline.duration;
    captions.push(open);
  }

  return captions.filter((caption) => caption.end > caption.start);
}

/**
 * Validátor čitelnosti. Kontroluje **skutečnou dobu zobrazení**, ne
 * deklarovanou — titulek zmizí, až ho vystřídá další, což ze samotného
 * scénáře není vidět.
 *
 * @param {Array<{start:number,end:number,text:string}>} captions
 * @returns {string[]} Varování, prázdné pole = v pořádku.
 */
export function checkReadability(captions) {
  const warnings = [];

  for (const caption of captions) {
    const shown = caption.end - caption.start;
    const needed = Math.max(MIN_SECONDS, caption.text.length / CHARS_PER_SECOND);

    if (shown < needed) {
      warnings.push(
        `titulek „${caption.text}" je vidět ${shown.toFixed(1)} s, `
        + `na přečtení je potřeba ${needed.toFixed(1)} s`,
      );
    }
    if (caption.text.length > MAX_CHARS) {
      warnings.push(
        `titulek „${caption.text}" má ${caption.text.length} znaků, `
        + `nad ${MAX_CHARS} se nedá přečíst na jeden pohled`,
      );
    }
  }

  return warnings;
}

/** `H:MM:SS.cc` — ASS neumí víc než setiny. */
function timestamp(seconds) {
  const total = Math.max(0, seconds);
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = Math.floor(total % 60);
  const cs = Math.round((total - Math.floor(total)) * 100);
  return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}.${String(cs).padStart(2, '0')}`;
}

/** Složené závorky uvozují v ASS override blok, konec řádku je `\N`. */
function escape(text) {
  return text
    .replace(/\\/g, '\\\\')
    .replace(/\{/g, '(')
    .replace(/\}/g, ')')
    .replace(/\r?\n/g, '\\N');
}

/**
 * @param {Array<{start:number,end:number,text:string}>} captions
 * @param {{w:number,h:number}} output Rozlišení, ve kterém se titulky vypálí.
 */
export function renderAss(captions, output) {
  // Titulky se vypalují až po škálování na výstupní rozlišení, takže
  // PlayRes odpovídá výstupu a písmo se nikde nezmenšuje — po downscalu
  // by bylo měkké.
  const fontSize = Math.round(output.h * 0.042);
  const marginV = Math.round(output.h * 0.07);
  const margin = Math.round(output.w * 0.06);

  const header = [
    '[Script Info]',
    'ScriptType: v4.00+',
    `PlayResX: ${output.w}`,
    `PlayResY: ${output.h}`,
    'WrapStyle: 0',
    'ScaledBorderAndShadow: yes',
    '',
    '[V4+ Styles]',
    'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour,'
      + ' BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle,'
      + ' BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
    // BorderStyle 3 kreslí za text plný obdélník barvou OutlineColour;
    // &H64000000 je černá s alfou 0x64, tedy zřetelná, ale ne neprůhledná.
    `Style: Default,${FONT},${fontSize},&H00FFFFFF,&H000000FF,&H64000000,&H00000000,`
      + `0,0,0,0,100,100,0,0,3,10,0,2,${margin},${margin},${marginV},1`,
    '',
    '[Events]',
    'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
  ];

  const lines = captions.map(
    (caption) => `Dialogue: 0,${timestamp(caption.start)},${timestamp(caption.end)},`
      + `Default,,0,0,0,,${escape(caption.text)}`,
  );

  return `${[...header, ...lines].join('\n')}\n`;
}
