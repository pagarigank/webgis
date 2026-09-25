import React, { useState, useEffect, useMemo } from 'react';

export interface BearingValue {
  quadrant?: 'NE' | 'SE' | 'SW' | 'NW' | null;
  deg?: number | null;
  min?: number | null;
  sec?: number | null;
  bearingStr?: string;
  azimuth_dd?: number | null;
  cardinal?: 'N' | 'E' | 'S' | 'W' | null;
}

interface BearingInputProps {
  value?: BearingValue;
  onChange: (val: BearingValue) => void;
  disabled?: boolean;
}

export const BearingInput: React.FC<BearingInputProps> = ({
  value,
  onChange,
  disabled = false,
}) => {
  const [prefix, setPrefix] = useState<'N' | 'S'>((value?.quadrant?.[0] as 'N' | 'S') || 'N');
  const [suffix, setSuffix] = useState<'E' | 'W'>((value?.quadrant?.[1] as 'E' | 'W') || 'E');
  const [deg, setDeg] = useState<string>(value?.deg !== undefined && value?.deg !== null ? String(value.deg) : '');
  const [min, setMin] = useState<string>(value?.min !== undefined && value?.min !== null ? String(value.min) : '');
  const [sec, setSec] = useState<string>(value?.sec !== undefined && value?.sec !== null ? String(value.sec) : '0');
  const [pasteMode, setPasteMode] = useState<boolean>(false);
  const [pasteText, setPasteText] = useState<string>('');

  // Sync state if external value changes
  useEffect(() => {
    if (value?.quadrant) {
      setPrefix(value.quadrant[0] as 'N' | 'S');
      setSuffix(value.quadrant[1] as 'E' | 'W');
    }
    if (value?.deg !== undefined && value?.deg !== null) {
      setDeg(String(value.deg));
    }
    if (value?.min !== undefined && value?.min !== null) {
      setMin(String(value.min));
    }
    if (value?.sec !== undefined && value?.sec !== null) {
      setSec(String(value.sec));
    }
  }, [value]);

  // Derive Azimuth and calculate live VR validation
  const { azimuth, validationError, formattedBearing } = useMemo(() => {
    const d = parseInt(deg, 10);
    const m = parseInt(min, 10);
    const s = parseFloat(sec);

    if (isNaN(d)) {
      return { azimuth: null, validationError: 'Degrees required', formattedBearing: '' };
    }

    if (d < 0 || d > 90) {
      return { azimuth: null, validationError: 'VR-01: Degrees must be 0–90', formattedBearing: '' };
    }
    if (!isNaN(m) && (m < 0 || m > 59)) {
      return { azimuth: null, validationError: 'VR-01: Minutes must be 0–59', formattedBearing: '' };
    }
    if (!isNaN(s) && (s < 0 || s >= 60)) {
      return { azimuth: null, validationError: 'VR-01: Seconds must be 0–59.999', formattedBearing: '' };
    }

    const totalAngle = d + (isNaN(m) ? 0 : m / 60) + (isNaN(s) ? 0 : s / 3600);

    // VR-07: Ambiguous 0° or 90°
    if (Math.abs(totalAngle - 0) < 1e-6) {
      return { azimuth: null, validationError: 'VR-07: Ambiguous 0° with quadrant; must be DUE NORTH or DUE SOUTH', formattedBearing: '' };
    }
    if (Math.abs(totalAngle - 90) < 1e-6) {
      return { azimuth: null, validationError: 'VR-07: Ambiguous 90° with quadrant; must be DUE EAST or DUE WEST', formattedBearing: '' };
    }

    const q = `${prefix}${suffix}` as 'NE' | 'SE' | 'SW' | 'NW';
    let az = 0;
    switch (q) {
      case 'NE': az = totalAngle; break;
      case 'SE': az = 180 - totalAngle; break;
      case 'SW': az = 180 + totalAngle; break;
      case 'NW': az = 360 - totalAngle; break;
    }

    const formatted = `${prefix} ${d}°${String(isNaN(m) ? 0 : m).padStart(2, '0')}'${(isNaN(s) ? 0 : s).toFixed(2).padStart(5, '0')}" ${suffix}`;

    return {
      azimuth: az,
      validationError: null,
      formattedBearing: formatted,
    };
  }, [prefix, suffix, deg, min, sec]);

  const emitChange = (newP: 'N' | 'S', newS: 'E' | 'W', newD: string, newM: string, newSec: string) => {
    const d = parseInt(newD, 10);
    const m = parseInt(newM, 10);
    const s = parseFloat(newSec);

    const q = `${newP}${newS}` as 'NE' | 'SE' | 'SW' | 'NW';
    const totalAngle = (isNaN(d) ? 0 : d) + (isNaN(m) ? 0 : m / 60) + (isNaN(s) ? 0 : s / 3600);
    let az = 0;
    switch (q) {
      case 'NE': az = totalAngle; break;
      case 'SE': az = 180 - totalAngle; break;
      case 'SW': az = 180 + totalAngle; break;
      case 'NW': az = 360 - totalAngle; break;
    }

    onChange({
      quadrant: q,
      deg: isNaN(d) ? null : d,
      min: isNaN(m) ? 0 : m,
      sec: isNaN(s) ? 0 : s,
      bearingStr: `${newP} ${newD}°${newM}'${newSec}" ${newS}`,
      azimuth_dd: isNaN(d) ? null : az,
    });
  };

  const handleQuickPaste = (text: string) => {
    const raw = text.trim().toUpperCase();
    if (!raw) return;

    // 1. Cardinal check
    if (/^(?:DUE\s+)?(NORTH|SOUTH|EAST|WEST|N|S|E|W)$/i.test(raw)) {
      const card = raw.replace('DUE', '').trim()[0] as 'N' | 'S' | 'E' | 'W';
      onChange({
        cardinal: card,
        azimuth_dd: card === 'N' ? 0 : card === 'E' ? 90 : card === 'S' ? 180 : 270,
        bearingStr: `DUE ${card}`,
      });
      setPasteMode(false);
      return;
    }

    // 2. Quadrant DMS regex
    const dmsMatch = raw.match(/^([NS])\s*(\d{1,2})[\s°dD-]+(\d{1,2})[\s'mM-]+([\d.]+)[\s"sS]*\s*([EW])$/u);
    if (dmsMatch) {
      const p = dmsMatch[1] as 'N' | 'S';
      const d = dmsMatch[2];
      const m = dmsMatch[3];
      const s = dmsMatch[4];
      const suf = dmsMatch[5] as 'E' | 'W';
      setPrefix(p);
      setSuffix(suf);
      setDeg(d);
      setMin(m);
      setSec(s);
      emitChange(p, suf, d, m, s);
      setPasteMode(false);
      return;
    }

    // 3. Decimal regex e.g. "N 25.5 E"
    const decMatch = raw.match(/^([NS])\s*(\d{1,2}(?:\.\d+)?)[\s°dD]*\s*([EW])$/u);
    if (decMatch) {
      const p = decMatch[1] as 'N' | 'S';
      const suf = decMatch[3] as 'E' | 'W';
      const dec = parseFloat(decMatch[2]);
      const d = Math.floor(dec);
      const rem = (dec - d) * 60;
      const m = Math.floor(rem);
      const s = ((rem - m) * 60).toFixed(2);
      setPrefix(p);
      setSuffix(suf);
      setDeg(String(d));
      setMin(String(m));
      setSec(s);
      emitChange(p, suf, String(d), String(m), s);
      setPasteMode(false);
      return;
    }

    // 4. Raw azimuth e.g. "115.5"
    const azMatch = raw.match(/^(\d+(?:\.\d+)?)\s*°?$/);
    if (azMatch) {
      const az = parseFloat(azMatch[1]);
      if (az >= 0 && az < 360) {
        onChange({
          azimuth_dd: az,
          bearingStr: `${az.toFixed(4)}°`,
        });
        setPasteMode(false);
        return;
      }
    }
  };

  return (
    <div className="flex flex-col gap-1 text-xs">
      {!pasteMode ? (
        <div className="flex items-center gap-1">
          {/* Prefix (N/S) */}
          <select
            value={prefix}
            disabled={disabled}
            onChange={(e) => {
              const p = e.target.value as 'N' | 'S';
              setPrefix(p);
              emitChange(p, suffix, deg, min, sec);
            }}
            className="border rounded px-1.5 py-1 font-semibold bg-white text-gray-800"
            title="Quadrant Prefix"
          >
            <option value="N">N</option>
            <option value="S">S</option>
          </select>

          {/* Degrees */}
          <div className="flex items-center">
            <input
              type="number"
              min="0"
              max="90"
              disabled={disabled}
              placeholder="0"
              value={deg}
              onChange={(e) => {
                setDeg(e.target.value);
                emitChange(prefix, suffix, e.target.value, min, sec);
              }}
              className="border rounded px-1.5 py-1 w-12 text-right"
              title="Degrees (0–90)"
            />
            <span className="px-0.5 text-gray-500">°</span>
          </div>

          {/* Minutes */}
          <div className="flex items-center">
            <input
              type="number"
              min="0"
              max="59"
              disabled={disabled}
              placeholder="00"
              value={min}
              onChange={(e) => {
                setMin(e.target.value);
                emitChange(prefix, suffix, deg, e.target.value, sec);
              }}
              className="border rounded px-1.5 py-1 w-11 text-right"
              title="Minutes (0–59)"
            />
            <span className="px-0.5 text-gray-500">'</span>
          </div>

          {/* Seconds */}
          <div className="flex items-center">
            <input
              type="number"
              step="0.01"
              min="0"
              max="59.99"
              disabled={disabled}
              placeholder="00.00"
              value={sec}
              onChange={(e) => {
                setSec(e.target.value);
                emitChange(prefix, suffix, deg, min, e.target.value);
              }}
              className="border rounded px-1.5 py-1 w-14 text-right"
              title="Seconds (0–59.999)"
            />
            <span className="px-0.5 text-gray-500">"</span>
          </div>

          {/* Suffix (E/W) */}
          <select
            value={suffix}
            disabled={disabled}
            onChange={(e) => {
              const s = e.target.value as 'E' | 'W';
              setSuffix(s);
              emitChange(prefix, s, deg, min, sec);
            }}
            className="border rounded px-1.5 py-1 font-semibold bg-white text-gray-800"
            title="Quadrant Suffix"
          >
            <option value="E">E</option>
            <option value="W">W</option>
          </select>

          {/* Paste Toggle Button */}
          <button
            type="button"
            onClick={() => setPasteMode(true)}
            className="text-xs text-blue-600 hover:text-blue-800 underline ml-1"
            title="Paste standard survey string"
          >
            Paste
          </button>
        </div>
      ) : (
        <div className="flex items-center gap-1">
          <input
            type="text"
            placeholder={'e.g. N 25°30\'00" E or DUE NORTH'}
            value={pasteText}
            onChange={(e) => setPasteText(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                handleQuickPaste(pasteText);
              }
            }}
            className="border rounded px-2 py-1 w-48 text-xs font-mono"
            autoFocus
          />
          <button
            type="button"
            onClick={() => handleQuickPaste(pasteText)}
            className="px-2 py-1 bg-blue-600 text-white rounded text-xs hover:bg-blue-700"
          >
            Apply
          </button>
          <button
            type="button"
            onClick={() => setPasteMode(false)}
            className="px-2 py-1 border rounded text-xs hover:bg-gray-50 text-gray-600"
          >
            Cancel
          </button>
        </div>
      )}

      {/* Live Azimuth and Validation Readout */}
      <div className="flex items-center gap-2">
        {azimuth !== null && (
          <span className="text-gray-500 text-[11px] font-mono" title={formattedBearing}>
            Az: <strong className="text-gray-700">{azimuth.toFixed(4)}°</strong>
          </span>
        )}
        {validationError && (
          <span className="text-red-600 text-[11px] font-medium" role="alert">
            ⚠ {validationError}
          </span>
        )}
      </div>
    </div>
  );
};
