/* Language from the device time zone (a VPN changes the IP, not the clock). Writes the tzl cookie for the server and,
   the first time only, reloads when the page language was guessed from the IP but the clock points elsewhere. */
(function () {
  var d = document.documentElement, tz;
  try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) { return; }
  if (!tz) return;
  var m = document.cookie.match(/(?:^|; )tzl=([^;]*)/), had = m ? decodeURIComponent(m[1]) : null;
  if (had === tz) return;
  document.cookie = 'tzl=' + encodeURIComponent(tz) + '; path=/; max-age=31536000; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');
  if (had !== null || d.getAttribute('data-lauto') !== '1') return;
  var map = {"fr":"Africa/Abidjan Africa/Bamako Africa/Bangui Africa/Brazzaville Africa/Bujumbura Africa/Conakry Africa/Dakar Africa/Douala Africa/Kinshasa Africa/Libreville Africa/Lome Africa/Lubumbashi Africa/Ndjamena Africa/Niamey Africa/Ouagadougou Africa/Porto-Novo America/Cayenne America/Guadeloupe America/Marigot America/Martinique America/Miquelon America/Port-au-Prince America/St_Barthelemy Europe/Brussels Europe/Luxembourg Europe/Monaco Europe/Paris Indian/Antananarivo Indian/Mayotte Indian/Reunion Pacific/Gambier Pacific/Marquesas Pacific/Noumea Pacific/Tahiti Pacific/Wallis","ar":"Africa/Algiers Africa/Cairo Africa/Casablanca Africa/Djibouti Africa/El_Aaiun Africa/Khartoum Africa/Mogadishu Africa/Nouakchott Africa/Tripoli Africa/Tunis Asia/Aden Asia/Amman Asia/Baghdad Asia/Bahrain Asia/Beirut Asia/Damascus Asia/Dubai Asia/Gaza Asia/Hebron Asia/Kuwait Asia/Muscat Asia/Qatar Asia/Riyadh Egypt Indian/Comoro","ru":"Asia/Almaty Asia/Anadyr Asia/Aqtau Asia/Aqtobe Asia/Ashgabat Asia/Atyrau Asia/Barnaul Asia/Bishkek Asia/Chita Asia/Dushanbe Asia/Irkutsk Asia/Kamchatka Asia/Khandyga Asia/Krasnoyarsk Asia/Magadan Asia/Novokuznetsk Asia/Novosibirsk Asia/Omsk Asia/Oral Asia/Qostanay Asia/Qyzylorda Asia/Sakhalin Asia/Samarkand Asia/Srednekolymsk Asia/Tashkent Asia/Tomsk Asia/Ust-Nera Asia/Vladivostok Asia/Yakutsk Asia/Yekaterinburg Asia/Yerevan Europe/Astrakhan Europe/Chisinau Europe/Kaliningrad Europe/Kirov Europe/Minsk Europe/Moscow Europe/Samara Europe/Saratov Europe/Ulyanovsk Europe/Volgograd W-SU","tr":"Asia/Baku Asia/Istanbul Europe/Istanbul Turkey","fa":"Asia/Kabul Asia/Tehran Iran"}, want = 'en';
  for (var k in map) if ((' ' + map[k] + ' ').indexOf(' ' + tz + ' ') >= 0) { want = k; break; }
  var on = (d.getAttribute('data-lon') || '').split(',');
  if (on.indexOf(want) < 0) want = d.getAttribute('data-ldef') || '';
  if (want && want !== d.lang && on.indexOf(want) >= 0 && document.cookie.indexOf('tzl=') >= 0) location.reload();
})();
