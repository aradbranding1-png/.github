<?php
/*
 * SVG sprite for the home page: flags, market skylines, product art, module icons and the cargo-ship banner art.
 * Pure markup (no inline styles or scripts) so it works under the strict CSP. Windows and containers are generated
 * with a fixed seed so the HTML stays byte-identical between requests (public cache + ETag).
 */
mt_srand(1405);
$windows = static function (int $x0, int $x1, int $y0, int $y1, int $step = 5, int $chance = 45): string {
    $out = '';
    for ($x = $x0; $x <= $x1; $x += $step) {
        for ($y = $y0; $y <= $y1; $y += $step) {
            if (mt_rand(0, 99) < $chance) {
                $out .= '<rect x="' . $x . '" y="' . $y . '" width="1.6" height="1.6"/>';
            }
        }
    }
    return $out;
};
$palette = ['#C99A3E', '#1E7FC4', '#A8383A', '#D9DEE6', '#2D7A5A', '#D0702E', '#3B4F8C', '#E3B85A'];
?>
<svg class="th-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
  <defs>
    <path id="tg-star" d="M0-1 .2245-.309.951-.309.363.118.588.809 0 .382-.588.809-.363.118-.951-.309-.2245-.309z"/>
    <linearGradient id="tgs-sky" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#071a33"/><stop offset=".45" stop-color="#173f6b"/><stop offset=".78" stop-color="#b9774a"/><stop offset="1" stop-color="#f3b866"/>
    </linearGradient>
    <linearGradient id="tgs-water" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#0c2a47"/><stop offset="1" stop-color="#030c18"/>
    </linearGradient>
    <linearGradient id="tgs-bld" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#1c3557"/><stop offset="1" stop-color="#08162a"/>
    </linearGradient>
    <linearGradient id="tgs-ivory" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#f6e7cf"/><stop offset=".6" stop-color="#d9c3a2"/><stop offset="1" stop-color="#9b8669"/>
    </linearGradient>
    <linearGradient id="tgs-glass" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#9ccbf0"/><stop offset=".5" stop-color="#3f6f9c"/><stop offset="1" stop-color="#16314f"/>
    </linearGradient>
    <radialGradient id="tgs-sun" cx=".5" cy=".5" r=".5">
      <stop offset="0" stop-color="#ffe2a0"/><stop offset="1" stop-color="#ffe2a0" stop-opacity="0"/>
    </radialGradient>
    <linearGradient id="tgb-sky" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#020914"/><stop offset=".6" stop-color="#0b2a4a"/><stop offset="1" stop-color="#c47a3c"/>
    </linearGradient>
    <linearGradient id="tgb-sea" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#0d3150"/><stop offset="1" stop-color="#020914"/>
    </linearGradient>
  </defs>

  <!-- Flags (30×20) -->
  <symbol id="flag-CN" viewBox="0 0 30 20"><rect width="30" height="20" fill="#DE2910"/><use href="#tg-star" transform="translate(5 5) scale(3)" fill="#FFDE00"/><use href="#tg-star" transform="translate(10 2) scale(1)" fill="#FFDE00"/><use href="#tg-star" transform="translate(12 4) scale(1)" fill="#FFDE00"/><use href="#tg-star" transform="translate(12 7) scale(1)" fill="#FFDE00"/><use href="#tg-star" transform="translate(10 9) scale(1)" fill="#FFDE00"/></symbol>
  <symbol id="flag-IN" viewBox="0 0 30 20"><rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#FF9933"/><rect y="13.33" width="30" height="6.67" fill="#138808"/><circle cx="15" cy="10" r="2.6" fill="none" stroke="#000080" stroke-width=".7"/><circle cx="15" cy="10" r=".6" fill="#000080"/></symbol>
  <symbol id="flag-AE" viewBox="0 0 30 20"><rect width="30" height="20" fill="#fff"/><rect width="30" height="6.67" fill="#00732F"/><rect y="13.33" width="30" height="6.67" fill="#000"/><rect width="8" height="20" fill="#FF0000"/></symbol>
  <symbol id="flag-TR" viewBox="0 0 30 20"><rect width="30" height="20" fill="#E30A17"/><circle cx="11.5" cy="10" r="5" fill="#fff"/><circle cx="12.8" cy="10" r="4" fill="#E30A17"/><use href="#tg-star" transform="translate(17.6 10) rotate(-18) scale(2.2)" fill="#fff"/></symbol>
  <symbol id="flag-DE" viewBox="0 0 30 20"><rect width="30" height="6.67" fill="#000"/><rect y="6.67" width="30" height="6.67" fill="#DD0000"/><rect y="13.33" width="30" height="6.67" fill="#FFCE00"/></symbol>
  <symbol id="flag-RU" viewBox="0 0 30 20"><rect width="30" height="6.67" fill="#fff"/><rect y="6.67" width="30" height="6.67" fill="#0039A6"/><rect y="13.33" width="30" height="6.67" fill="#D52B1E"/></symbol>
  <symbol id="flag-BR" viewBox="0 0 30 20"><rect width="30" height="20" fill="#009C3B"/><path d="M15 2.2 27.4 10 15 17.8 2.6 10z" fill="#FFDF00"/><circle cx="15" cy="10" r="4.4" fill="#002776"/><path d="M10.8 9.2q4.4-1.2 8.4 1.6" stroke="#fff" stroke-width=".7" fill="none"/></symbol>
  <symbol id="flag-IQ" viewBox="0 0 30 20"><rect width="30" height="6.67" fill="#CE1126"/><rect y="6.67" width="30" height="6.67" fill="#fff"/><rect y="13.33" width="30" height="6.67" fill="#000"/><path d="M10 11.5h10" stroke="#007A3D" stroke-width="1.6"/></symbol>
  <symbol id="flag-AF" viewBox="0 0 30 20"><rect width="10" height="20" fill="#000"/><rect x="10" width="10" height="20" fill="#D32011"/><rect x="20" width="10" height="20" fill="#007A36"/><circle cx="15" cy="10" r="3" fill="none" stroke="#fff" stroke-width=".8"/></symbol>
  <symbol id="flag-KE" viewBox="0 0 30 20"><rect width="30" height="20" fill="#fff"/><rect width="30" height="5.6" fill="#000"/><rect y="7" width="30" height="6" fill="#BB0000"/><rect y="14.4" width="30" height="5.6" fill="#006600"/><ellipse cx="15" cy="10" rx="2.6" ry="5" fill="#BB0000" stroke="#000" stroke-width=".5"/><path d="M15 5.5v9" stroke="#fff" stroke-width=".6"/></symbol>
  <symbol id="flag-TZ" viewBox="0 0 30 20"><path d="M0 0h30L0 20z" fill="#1EB53A"/><path d="M30 0v20H0z" fill="#00A3DD"/><path d="M0 20 30 0" stroke="#FCD116" stroke-width="7"/><path d="M0 20 30 0" stroke="#000" stroke-width="4.6"/></symbol>
  <symbol id="flag-ZA" viewBox="0 0 30 20"><rect width="30" height="10" fill="#E03C31"/><rect y="10" width="30" height="10" fill="#001489"/><path d="M0 0 11 10 0 20M11 10h19" stroke="#fff" stroke-width="6.4" fill="none"/><path d="M0 0 11 10 0 20M11 10h19" stroke="#007749" stroke-width="3.8" fill="none"/><path d="M0 2.6 8.2 10 0 17.4z" fill="#FFB81C"/><path d="M0 4.2 6.4 10 0 15.8z" fill="#000"/></symbol>
  <symbol id="flag-NG" viewBox="0 0 30 20"><rect width="30" height="20" fill="#fff"/><rect width="10" height="20" fill="#008751"/><rect x="20" width="10" height="20" fill="#008751"/></symbol>
  <symbol id="flag-US" viewBox="0 0 30 20"><rect width="30" height="20" fill="#B22234"/><g fill="#fff"><rect y="1.54" width="30" height="1.54"/><rect y="4.62" width="30" height="1.54"/><rect y="7.69" width="30" height="1.54"/><rect y="10.77" width="30" height="1.54"/><rect y="13.85" width="30" height="1.54"/><rect y="16.92" width="30" height="1.54"/></g><rect width="12.5" height="10.77" fill="#3C3B6E"/><g fill="#fff"><circle cx="2.5" cy="2.3" r=".6"/><circle cx="6.2" cy="2.3" r=".6"/><circle cx="10" cy="2.3" r=".6"/><circle cx="4.3" cy="5.3" r=".6"/><circle cx="8.1" cy="5.3" r=".6"/><circle cx="2.5" cy="8.3" r=".6"/><circle cx="6.2" cy="8.3" r=".6"/><circle cx="10" cy="8.3" r=".6"/></g></symbol>
  <symbol id="flag-CA" viewBox="0 0 30 20"><rect width="30" height="20" fill="#fff"/><rect width="7.5" height="20" fill="#D52B1E"/><rect x="22.5" width="7.5" height="20" fill="#D52B1E"/><path d="M15 4.2l1 2 1.8-.6-.6 3 1.6-.8.4 1-2 .9.4 1.3-2-.4V14h-1.2v-3.4l-2 .4.4-1.3-2-.9.4-1 1.6.8-.6-3 1.8.6z" fill="#D52B1E"/></symbol>
  <symbol id="flag-GB" viewBox="0 0 30 20"><rect width="30" height="20" fill="#012169"/><path d="M0 0l30 20M30 0L0 20" stroke="#fff" stroke-width="4"/><path d="M0 0l30 20M30 0L0 20" stroke="#C8102E" stroke-width="1.6"/><path d="M15 0v20M0 10h30" stroke="#fff" stroke-width="6"/><path d="M15 0v20M0 10h30" stroke="#C8102E" stroke-width="3.4"/></symbol>
  <symbol id="flag-NL" viewBox="0 0 30 20"><rect width="30" height="6.67" fill="#AE1C28"/><rect y="6.67" width="30" height="6.67" fill="#fff"/><rect y="13.33" width="30" height="6.67" fill="#21468B"/></symbol>
  <symbol id="flag-IR" viewBox="0 0 30 20"><rect width="30" height="6.67" fill="#239F40"/><rect y="6.67" width="30" height="6.67" fill="#fff"/><rect y="13.33" width="30" height="6.67" fill="#DA0000"/><circle cx="15" cy="10" r="1.6" fill="none" stroke="#DA0000" stroke-width=".6"/></symbol>

  <!-- Market skylines (240×150) -->
  <symbol id="sky-CN" viewBox="0 0 240 150">
    <rect width="240" height="150" fill="url(#tgs-sky)"/><circle cx="196" cy="104" r="34" fill="url(#tgs-sun)"/>
    <g fill="url(#tgs-bld)">
      <rect x="6" y="80" width="16" height="40"/><rect x="24" y="68" width="12" height="52"/><rect x="38" y="88" width="14" height="32"/>
      <rect x="92" y="62" width="14" height="58"/><rect x="186" y="74" width="16" height="46"/><rect x="204" y="86" width="14" height="34"/><rect x="220" y="70" width="14" height="50"/>
    </g>
    <path d="M128 120V30q5-14 10 0v90z" fill="url(#tgs-glass)"/><path d="M134 12v18" stroke="#b9d7f0" stroke-width="1.2"/>
    <path d="M146 120V46h4v-8h6v-8h4v8h6v8h4v74z" fill="url(#tgs-bld)"/>
    <path d="M168 120V30l14-6v96zm6-80h4v6h-4z" fill="#21456d"/>
    <path d="M70 120 63 96M70 120l7-24M70 14v106" stroke="#c9d5e4" stroke-width="2.2" fill="none"/>
    <circle cx="70" cy="88" r="10" fill="#c9566b"/><circle cx="70" cy="56" r="7" fill="#d0647a"/><circle cx="70" cy="34" r="3" fill="#d87a8e"/>
    <g fill="#FFD978" opacity=".85"><?= $windows(8, 20, 84, 116) . $windows(26, 34, 72, 116) . $windows(94, 104, 66, 116) . $windows(148, 168, 50, 116, 5, 35) . $windows(188, 232, 78, 116, 6, 40) ?></g>
    <rect y="120" width="240" height="30" fill="url(#tgs-water)"/>
    <g stroke="#F5C65D" stroke-width="1" opacity=".55"><path d="M60 126h20M124 128h18M150 132h22M30 134h14M186 130h20M66 140h10"/></g>
  </symbol>

  <symbol id="sky-IN" viewBox="0 0 240 150">
    <rect width="240" height="150" fill="url(#tgs-sky)"/><circle cx="40" cy="100" r="30" fill="url(#tgs-sun)"/>
    <g fill="url(#tgs-ivory)">
      <rect x="40" y="108" width="160" height="12"/>
      <rect x="62" y="44" width="5" height="64"/><path d="M60 44h9l-4.5-8z"/><rect x="173" y="44" width="5" height="64"/><path d="M171 44h9l-4.5-8z"/>
      <rect x="84" y="70" width="72" height="38"/>
      <path d="M100 70q0-24 20-36 20 12 20 36z"/><path d="M120 34v-10" stroke="#e9d9bd" stroke-width="1.4"/>
      <path d="M86 70q0-10 8-14 8 4 8 14zM138 70q0-10 8-14 8 4 8 14z"/>
    </g>
    <path d="M108 108V86q12-12 24 0v22z" fill="#5a4a39"/><path d="M90 104V90q4-5 8 0v14zM142 104V90q4-5 8 0v14z" fill="#6b5a46"/>
    <g fill="#0d2a1f"><path d="M0 120q12-22 24 0zM210 120q14-26 30 0zM14 120q8-14 18 0z"/></g>
    <rect y="120" width="240" height="30" fill="url(#tgs-water)"/>
    <path d="M100 124h40l-6 18h-28z" fill="#f6e7cf" opacity=".12"/>
    <g stroke="#F5C65D" stroke-width="1" opacity=".5"><path d="M30 128h16M190 132h18M110 138h20"/></g>
  </symbol>

  <symbol id="sky-AE" viewBox="0 0 240 150">
    <rect width="240" height="150" fill="url(#tgs-sky)"/><circle cx="60" cy="98" r="32" fill="url(#tgs-sun)"/>
    <g fill="url(#tgs-bld)">
      <rect x="10" y="84" width="14" height="36"/><rect x="28" y="72" width="12" height="48"/><rect x="44" y="92" width="16" height="28"/><rect x="66" y="64" width="12" height="56"/>
      <rect x="154" y="70" width="14" height="50"/><rect x="172" y="58" width="12" height="62"/><rect x="190" y="80" width="16" height="40"/><rect x="210" y="66" width="12" height="54"/><rect x="226" y="88" width="14" height="32"/>
    </g>
    <path d="M118 6v24l-4 4v20l-6 6v24l-8 8v28h40V92l-8-8V60l-6-6V34l-4-4V6z" fill="url(#tgs-glass)"/>
    <path d="M118 6v114" stroke="#cfe6fa" stroke-width=".8" opacity=".6"/>
    <path d="M88 120V76q8-10 16 0v44zM136 120V82l14-8v46z" fill="#1d3c60"/>
    <g fill="#FFD978" opacity=".85"><?= $windows(12, 22, 88, 116) . $windows(30, 38, 76, 116) . $windows(68, 76, 68, 116) . $windows(110, 128, 40, 116, 5, 30) . $windows(156, 222, 64, 116, 6, 35) ?></g>
    <rect y="120" width="240" height="30" fill="url(#tgs-water)"/>
    <g stroke="#F5C65D" stroke-width="1" opacity=".55"><path d="M108 126h20M112 132h14M116 138h8M20 130h16M170 128h20M200 136h16"/></g>
  </symbol>

  <symbol id="sky-TR" viewBox="0 0 240 150">
    <rect width="240" height="150" fill="url(#tgs-sky)"/><circle cx="196" cy="96" r="34" fill="url(#tgs-sun)"/>
    <g fill="#24324f">
      <rect x="70" y="84" width="100" height="36"/>
      <path d="M90 84q0-30 30-34 30 4 30 34z"/><path d="M120 50v-10" stroke="#c9a45c" stroke-width="1.4"/>
      <path d="M72 92q0-14 14-16 14 2 14 16zM140 92q0-14 14-16 14 2 14 16z"/>
      <rect x="58" y="40" width="5" height="80"/><path d="M58 40h5l-2.5-14z"/>
      <rect x="177" y="40" width="5" height="80"/><path d="M177 40h5l-2.5-14z"/>
      <rect x="40" y="56" width="4" height="64"/><path d="M40 56h4l-2-11z"/>
      <rect x="196" y="56" width="4" height="64"/><path d="M196 56h4l-2-11z"/>
    </g>
    <g fill="#b7543c" opacity=".55"><path d="M90 84q0-30 30-34v34z"/></g>
    <g fill="#FFD978" opacity=".9"><rect x="80" y="100" width="3" height="6"/><rect x="92" y="100" width="3" height="6"/><rect x="104" y="100" width="3" height="6"/><rect x="134" y="100" width="3" height="6"/><rect x="146" y="100" width="3" height="6"/><rect x="158" y="100" width="3" height="6"/><rect x="116" y="96" width="8" height="12"/></g>
    <g fill="#0f2037"><rect x="0" y="100" width="34" height="20"/><rect x="206" y="104" width="34" height="16"/></g>
    <rect y="120" width="240" height="30" fill="url(#tgs-water)"/>
    <g stroke="#F5C65D" stroke-width="1" opacity=".5"><path d="M110 126h20M114 132h12M40 130h18M180 134h20"/></g>
  </symbol>

  <symbol id="sky-DE" viewBox="0 0 240 150">
    <rect width="240" height="150" fill="url(#tgs-sky)"/><circle cx="60" cy="100" r="34" fill="url(#tgs-sun)"/>
    <g fill="url(#tgs-bld)"><rect x="4" y="84" width="22" height="36"/><rect x="196" y="78" width="18" height="42"/><rect x="216" y="88" width="24" height="32"/><rect x="170" y="40" width="5" height="80"/><circle cx="172.5" cy="44" r="7"/></g>
    <g fill="#d8c9a8"><rect x="70" y="66" width="100" height="10"/><rect x="66" y="58" width="108" height="9"/><rect x="112" y="46" width="16" height="12"/><path d="M114 46l6-8 6 8z"/>
      <rect x="74" y="76" width="9" height="44"/><rect x="92" y="76" width="9" height="44"/><rect x="110" y="76" width="9" height="44"/><rect x="128" y="76" width="9" height="44"/><rect x="146" y="76" width="9" height="44"/><rect x="160" y="76" width="9" height="44"/></g>
    <g fill="#FFD978" opacity=".85"><?= $windows(6, 22, 88, 116) . $windows(198, 236, 82, 116, 6, 40) ?></g>
    <rect y="120" width="240" height="30" fill="url(#tgs-water)"/>
    <g stroke="#F5C65D" stroke-width="1" opacity=".5"><path d="M90 126h60M110 134h24"/></g>
  </symbol>
  <symbol id="sky-RU" viewBox="0 0 240 150">
    <rect width="240" height="150" fill="url(#tgs-sky)"/><circle cx="190" cy="98" r="34" fill="url(#tgs-sun)"/>
    <g fill="url(#tgs-bld)"><rect x="0" y="92" width="26" height="28"/><rect x="200" y="86" width="40" height="34"/></g>
    <rect x="70" y="80" width="100" height="40" fill="#7a2c2c"/><rect x="60" y="96" width="120" height="24" fill="#5e2323"/>
    <g fill="#c99a3e"><rect x="114" y="44" width="12" height="36"/><path d="M110 46q10-22 20 0z"/><path d="M120 22v8" stroke="#c99a3e" stroke-width="1.5"/></g>
    <g fill="#2f7a5a"><rect x="84" y="58" width="10" height="22"/><path d="M80 60q9-18 18 0z"/></g>
    <g fill="#2a5a9c"><rect x="146" y="58" width="10" height="22"/><path d="M142 60q9-18 18 0z"/></g>
    <g fill="#c4572e"><rect x="98" y="66" width="8" height="14"/><path d="M95 68q7-14 14 0z"/><rect x="134" y="66" width="8" height="14"/><path d="M131 68q7-14 14 0z"/></g>
    <g fill="#FFD978" opacity=".85"><rect x="76" y="100" width="3" height="6"/><rect x="90" y="100" width="3" height="6"/><rect x="146" y="100" width="3" height="6"/><rect x="160" y="100" width="3" height="6"/><rect x="117" y="96" width="6" height="12"/><?= $windows(204, 236, 90, 116, 6, 40) ?></g>
    <rect y="120" width="240" height="30" fill="url(#tgs-water)"/>
    <g stroke="#F5C65D" stroke-width="1" opacity=".5"><path d="M100 126h40M110 133h20"/></g>
  </symbol>
  <symbol id="sky-IQ" viewBox="0 0 240 150">
    <rect width="240" height="150" fill="url(#tgs-sky)"/><circle cx="50" cy="96" r="34" fill="url(#tgs-sun)"/>
    <g fill="url(#tgs-bld)"><rect x="150" y="70" width="16" height="50"/><rect x="170" y="58" width="14" height="62"/><rect x="190" y="76" width="20" height="44"/><rect x="214" y="66" width="14" height="54"/></g>
    <g fill="#c9a45c"><path d="M70 120V84q22-26 44 0v36z"/><rect x="60" y="94" width="64" height="26"/><path d="M92 58v-8" stroke="#c9a45c" stroke-width="1.6"/><path d="M84 60q8-12 16 0z"/></g>
    <g fill="#2f6f9c" opacity=".85"><path d="M74 84q18-20 36 0z"/></g>
    <g fill="#b9944f"><rect x="40" y="46" width="6" height="74"/><path d="M40 46h6l-3-12z"/><rect x="132" y="50" width="6" height="70"/><path d="M132 50h6l-3-12z"/></g>
    <g fill="#FFD978" opacity=".85"><rect x="88" y="100" width="8" height="12"/><?= $windows(152, 226, 62, 116, 6, 35) ?></g>
    <rect y="120" width="240" height="30" fill="url(#tgs-water)"/>
    <g stroke="#F5C65D" stroke-width="1" opacity=".5"><path d="M70 126h40M80 133h20M170 130h24"/></g>
  </symbol>
  <symbol id="sky-GEN" viewBox="0 0 240 150">
    <rect width="240" height="150" fill="url(#tgs-sky)"/><circle cx="120" cy="100" r="40" fill="url(#tgs-sun)"/>
    <g fill="url(#tgs-bld)">
      <rect x="4" y="88" width="18" height="32"/><rect x="26" y="70" width="14" height="50"/><rect x="44" y="80" width="20" height="40"/><rect x="68" y="56" width="16" height="64"/>
      <rect x="150" y="66" width="16" height="54"/><rect x="170" y="50" width="14" height="70"/><rect x="188" y="78" width="20" height="42"/><rect x="212" y="62" width="14" height="58"/><rect x="228" y="90" width="12" height="30"/>
    </g>
    <path d="M100 120V40l10-12 10 12v80zM124 120V58h18v62z" fill="url(#tgs-glass)"/><path d="M110 28V14" stroke="#cfe6fa" stroke-width="1"/>
    <g fill="#FFD978" opacity=".85"><?= $windows(6, 18, 92, 116) . $windows(28, 36, 74, 116) . $windows(46, 60, 84, 116) . $windows(70, 80, 60, 116) . $windows(152, 236, 54, 116, 6, 35) ?></g>
    <rect y="120" width="240" height="30" fill="url(#tgs-water)"/>
    <g stroke="#F5C65D" stroke-width="1" opacity=".5"><path d="M100 126h22M106 132h12M40 130h16M180 134h20"/></g>
  </symbol>

  <!-- Product art (80×56) -->
  <symbol id="prod-saffron" viewBox="0 0 80 56">
    <rect width="80" height="56" rx="8" fill="#2a0c0c"/>
    <g fill="none" stroke-linecap="round">
      <?php for ($i = 0; $i < 22; $i++):
          $x = mt_rand(6, 70); $y = mt_rand(10, 48); $dx = mt_rand(-14, 14); $dy = mt_rand(-10, 4); ?>
        <path d="M<?= $x ?> <?= $y ?>q<?= intdiv($dx, 2) ?> <?= $dy - 6 ?> <?= $dx ?> <?= $dy ?>" stroke="<?= $i % 4 === 0 ? '#f08a24' : '#c4141c' ?>" stroke-width="<?= $i % 3 === 0 ? 2.2 : 1.6 ?>"/>
      <?php endfor; ?>
    </g>
    <ellipse cx="40" cy="52" rx="34" ry="5" fill="#000" opacity=".3"/>
  </symbol>
  <symbol id="prod-dates" viewBox="0 0 80 56">
    <rect width="80" height="56" rx="8" fill="#1c140e"/>
    <ellipse cx="40" cy="44" rx="34" ry="9" fill="#3a2a1d"/>
    <?php foreach ([[22, 30, -20], [38, 26, 10], [54, 32, 25], [30, 40, 5], [48, 42, -10], [62, 22, 40], [16, 20, -35]] as [$x, $y, $r]): ?>
      <g transform="translate(<?= $x ?> <?= $y ?>) rotate(<?= $r ?>)"><ellipse rx="9" ry="5.5" fill="#6b3214"/><ellipse rx="9" ry="5.5" fill="#8a4a1e" transform="translate(-1 -1.4) scale(.82)"/><path d="M-5-2q4-2 8 0" stroke="#c78a4e" stroke-width="1" fill="none"/></g>
    <?php endforeach; ?>
  </symbol>
  <symbol id="prod-pistachio" viewBox="0 0 80 56">
    <rect width="80" height="56" rx="8" fill="#1a1810"/>
    <?php foreach ([[20, 22, -25], [40, 18, 15], [60, 24, 40], [28, 38, 10], [50, 38, -30], [12, 42, 60], [68, 42, -10]] as [$x, $y, $r]): ?>
      <g transform="translate(<?= $x ?> <?= $y ?>) rotate(<?= $r ?>)"><ellipse rx="9" ry="6.5" fill="#e3d2ae"/><path d="M-6 0q6-4 12 0q-6 3-12 0z" fill="#7aa337"/><path d="M-6 0q6 2 12 0" stroke="#8a6b3d" stroke-width=".7" fill="none"/><ellipse cx="2" cy="-3" rx="3" ry="1.2" fill="#fff" opacity=".35"/></g>
    <?php endforeach; ?>
  </symbol>
  <symbol id="prod-petro" viewBox="0 0 80 56">
    <rect width="80" height="56" rx="8" fill="#071526"/>
    <rect y="40" width="80" height="16" fill="#0a1f36"/>
    <g fill="#1d3a5c"><rect x="8" y="18" width="7" height="26"/><rect x="18" y="26" width="10" height="18"/><rect x="31" y="10" width="5" height="34"/><rect x="40" y="22" width="14" height="22" rx="7"/><rect x="58" y="14" width="6" height="30"/><rect x="66" y="28" width="8" height="16"/></g>
    <path d="M33.5 10q-3-6 0-9 4 4 0 9z" fill="#f5a623"/>
    <g fill="#FFD978"><circle cx="11" cy="22" r="1"/><circle cx="11" cy="30" r="1"/><circle cx="23" cy="30" r="1"/><circle cx="33.5" cy="20" r="1"/><circle cx="33.5" cy="30" r="1"/><circle cx="47" cy="30" r="1"/><circle cx="61" cy="20" r="1"/><circle cx="61" cy="30" r="1"/><circle cx="70" cy="32" r="1"/></g>
    <g stroke="#F5C65D" stroke-width=".8" opacity=".5"><path d="M10 46h10M30 49h14M54 46h12"/></g>
  </symbol>
  <symbol id="prod-carpet" viewBox="0 0 80 56">
    <rect width="80" height="56" rx="8" fill="#22101a"/>
    <rect x="12" y="8" width="56" height="40" rx="2" fill="#8f1f2c"/><rect x="16" y="12" width="48" height="32" fill="none" stroke="#e8c27a" stroke-width="1.4"/>
    <path d="M40 16l12 12-12 12-12-12z" fill="#1b3a6b" stroke="#e8c27a" stroke-width="1"/><circle cx="40" cy="28" r="3" fill="#e8c27a"/>
    <g stroke="#e8c27a" stroke-width=".8"><path d="M12 8v40M68 8v40"/></g>
  </symbol>

  <!-- Module icons (24×24, stroke) -->
  <symbol id="m-chart" viewBox="0 0 24 24"><path d="M4 20h16"/><path d="M6 16v-3M10 16v-6M14 16v-4M18 16V8"/><path d="M5 10l5-4 4 3 6-5"/><path d="M16 4h4v4"/></symbol>
  <symbol id="m-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.6 2.4 3.8 5.2 3.8 8.5S14.6 18.1 12 20.5M12 3.5C9.4 5.9 8.2 8.7 8.2 12s1.2 6.1 3.8 8.5"/></symbol>
  <symbol id="m-people" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.4"/><path d="M3.5 19c.8-3.4 3-5 5.5-5s4.7 1.6 5.5 5M14.5 14.4c.8-.3 1.6-.4 2.5-.4 2 0 3.6 1.3 4 4"/></symbol>
  <symbol id="m-letter" viewBox="0 0 24 24"><rect x="3.5" y="6" width="17" height="12" rx="2"/><path d="M4 7l8 6 8-6"/></symbol>
  <symbol id="m-page" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M4 9h16M9 20V9"/></symbol>
  <symbol id="m-star" viewBox="0 0 24 24"><path d="M12 3.8l2.5 5.2 5.6.7-4.1 3.9 1 5.6-5-2.7-5 2.7 1-5.6-4.1-3.9 5.6-.7z"/></symbol>
  <symbol id="m-ship" viewBox="0 0 24 24"><path d="M3 15h18l-2.5 4.5h-13z"/><path d="M6 15v-4h12v4M9 11V7h6v4M12 7V4"/></symbol>
  <symbol id="m-plane" viewBox="0 0 24 24"><path d="M21 12.5 3 7.5l1.8-1.6 7.2 2.6 4.5-4.2c.8-.7 2 .1 1.6 1.1L16 10l4.4 1.4z"/><path d="M8 14l-2 5 2.5-.5 3.5-3.5"/></symbol>
  <symbol id="m-anchor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="2"/><path d="M12 7v13M8 10h8M4.5 13c0 4 3.3 7 7.5 7s7.5-3 7.5-7"/></symbol>
  <symbol id="m-arrow" viewBox="0 0 24 24"><path d="M19 12H5M11 6l-6 6 6 6"/></symbol>
  <symbol id="m-play" viewBox="0 0 24 24"><path d="M9 6.5v11l9-5.5z" fill="currentColor"/></symbol>
  <symbol id="m-box" viewBox="0 0 24 24"><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/></symbol>
  <symbol id="m-menu" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></symbol>
  <symbol id="m-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></symbol>
  <symbol id="m-coin" viewBox="0 0 24 24"><ellipse cx="12" cy="7" rx="7" ry="3"/><path d="M5 7v5c0 1.7 3.1 3 7 3s7-1.3 7-3V7M5 12v5c0 1.7 3.1 3 7 3s7-1.3 7-3v-5"/></symbol>
  <symbol id="m-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></symbol>
  <symbol id="m-filter" viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></symbol>

  <!-- Banner art (600×180) -->
  <symbol id="banner-ship" viewBox="0 0 600 180" preserveAspectRatio="xMidYMid slice">
    <rect width="600" height="180" fill="url(#tgb-sky)"/>
    <g fill="#071a2e"><?php for ($x = 0; $x < 600; $x += 14): $h = mt_rand(4, 26); ?><rect x="<?= $x ?>" y="<?= 108 - $h ?>" width="12" height="<?= $h ?>"/><?php endfor; ?></g>
    <g fill="#FFD978" opacity=".8"><?= $windows(2, 596, 88, 104, 7, 22) ?></g>
    <g stroke="#173252" stroke-width="3" fill="none">
      <path d="M470 108V42h6v66M476 46h70M540 46v14M500 46l-24 20"/><path d="M520 108V54h6v54M526 58h56M576 58v12"/>
    </g>
    <rect y="108" width="600" height="72" fill="url(#tgb-sea)"/>
    <g transform="translate(110 0)">
      <path d="M0 112h360l-18 24H22z" fill="#2c4672"/><path d="M0 112h360v3H2z" fill="#9fb4d4"/><path d="M14 128h336l-6 8H22z" fill="#9c3a34"/>
      <?php for ($b = 0; $b < 22; $b++):
          $tiers = mt_rand(2, 4); for ($t = 0; $t < $tiers; $t++): ?>
        <rect x="<?= 40 + $b * 12 ?>" y="<?= 104 - $t * 8 ?>" width="11" height="7.4" fill="<?= $palette[mt_rand(0, count($palette) - 1)] ?>"/>
      <?php endfor; endfor; ?>
      <rect x="318" y="80" width="26" height="32" fill="#e8ecf2"/><rect x="314" y="84" width="34" height="4" fill="#0b1426"/><rect x="316" y="85" width="30" height="2" fill="#FFD978"/>
      <rect x="326" y="66" width="8" height="14" fill="#1b2438"/><rect x="326" y="68" width="8" height="3" fill="#b43a33"/>
      <circle cx="350" cy="76" r="2" fill="#36F58A"/><circle cx="4" cy="108" r="2" fill="#fff"/>
    </g>
    <g fill="#F5C65D" opacity=".35"><path d="M70 140h300l-20 6H90z"/></g>
    <g stroke="#9fe2ff" stroke-width="1" opacity=".35" fill="none"><path d="M420 132q30 6 90 6M418 138q40 8 120 10"/></g>
  </symbol>
</svg>
