import React, { useMemo } from 'react';
import type { TechnicalDescriptionCourse } from '../api/surveyApi';

interface TraversePreviewMapProps {
  courses: TechnicalDescriptionCourse[];
  pobLabel?: string;
}

interface Vertex {
  seq: number;
  label: string;
  easting: number;
  northing: number;
}

export const TraversePreviewMap: React.FC<TraversePreviewMapProps> = ({
  courses,
  pobLabel = '1',
}) => {
  // Compute open traverse vertices
  const { vertices, closureGap, perimeter, isValid } = useMemo(() => {
    if (!courses || courses.length === 0) {
      return { vertices: [], closureGap: 0, perimeter: 0, isValid: false };
    }

    const pts: Vertex[] = [{ seq: 0, label: pobLabel, easting: 0, northing: 0 }];
    let curE = 0;
    let curN = 0;
    let totalPerimeter = 0;

    for (const c of courses) {
      const az = c.bearing.azimuth_dd;
      const dist = c.distance.meters ?? c.distance.value;

      if (az === null || dist === null || dist <= 0) {
        continue;
      }

      totalPerimeter += dist;
      const azRad = (az * Math.PI) / 180.0;
      const deltaN = dist * Math.cos(azRad);
      const deltaE = dist * Math.sin(azRad);

      curN += deltaN;
      curE += deltaE;

      pts.push({
        seq: c.seq,
        label: c.to_point_label || String(c.seq + 1),
        easting: curE,
        northing: curN,
      });
    }

    if (pts.length < 2) {
      return { vertices: pts, closureGap: 0, perimeter: totalPerimeter, isValid: false };
    }

    const lastPt = pts[pts.length - 1];
    const firstPt = pts[0];
    const gap = Math.sqrt(
      Math.pow(lastPt.easting - firstPt.easting, 2) + Math.pow(lastPt.northing - firstPt.northing, 2)
    );

    return {
      vertices: pts,
      closureGap: gap,
      perimeter: totalPerimeter,
      isValid: pts.length >= 3,
    };
  }, [courses, pobLabel]);

  // Compute SVG viewBox and projection to fit bounds
  const svgData = useMemo(() => {
    if (vertices.length < 2) return null;

    let minE = Infinity;
    let maxE = -Infinity;
    let minN = Infinity;
    let maxN = -Infinity;

    for (const p of vertices) {
      if (p.easting < minE) minE = p.easting;
      if (p.easting > maxE) maxE = p.easting;
      if (p.northing < minN) minN = p.northing;
      if (p.northing > maxN) maxN = p.northing;
    }

    const width = Math.max(maxE - minE, 10);
    const height = Math.max(maxN - minN, 10);
    const padding = Math.max(width, height) * 0.15;

    const viewBox = `${minE - padding} ${-(maxN + padding)} ${width + padding * 2} ${height + padding * 2}`;

    // Path string (Y is inverted for SVG coordinate space)
    const pointsStr = vertices.map((p) => `${p.easting},${-p.northing}`).join(' ');

    return {
      viewBox,
      pointsStr,
      minE,
      maxE,
      minN,
      maxN,
    };
  }, [vertices]);

  if (vertices.length < 2) {
    return (
      <div className="flex flex-col items-center justify-center p-8 bg-gray-50 border border-dashed rounded-lg text-gray-400 text-sm">
        <span>No traverse geometry available to preview.</span>
        <span className="text-xs text-gray-400 mt-1">Add valid courses to see the real-time polygon preview.</span>
      </div>
    );
  }

  const firstPt = vertices[0];
  const lastPt = vertices[vertices.length - 1];

  return (
    <div className="flex flex-col gap-2 bg-white border rounded-lg p-3 shadow-sm">
      <div className="flex items-center justify-between border-b pb-2">
        <div className="flex items-center gap-2">
          <span className="font-semibold text-xs text-gray-700 uppercase tracking-wider">
            Live Traverse Preview
          </span>
          {closureGap <= 0.10 ? (
            <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-green-100 text-green-800">
              Closed (Gap: {closureGap.toFixed(3)} m)
            </span>
          ) : (
            <span className="px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-100 text-amber-800 flex items-center gap-1">
              <span>⚠ Open Polygon Gap:</span>
              <strong>{closureGap.toFixed(3)} m</strong>
            </span>
          )}
        </div>
        <div className="text-xs text-gray-500 font-mono">
          Perimeter: <strong>{perimeter.toFixed(2)} m</strong> · Vertices: <strong>{vertices.length}</strong>
        </div>
      </div>

      {/* SVG Canvas for Traverse Display */}
      <div className="relative w-full h-64 bg-slate-900 rounded overflow-hidden flex items-center justify-center">
        {svgData && (
          <svg
            viewBox={svgData.viewBox}
            className="w-full h-full p-2"
            preserveAspectRatio="xMidYMid meet"
          >
            {/* Grid Lines */}
            <defs>
              <pattern id="grid" width="20" height="20" patternUnits="userSpaceOnUse">
                <path d="M 20 0 L 0 0 0 20" fill="none" stroke="#1e293b" strokeWidth="0.5" />
              </pattern>
            </defs>

            {/* Filled Polygon (semi-transparent green if closed/plausible) */}
            {isValid && (
              <polygon
                points={svgData.pointsStr}
                fill={closureGap < 0.2 ? 'rgba(34, 197, 94, 0.15)' : 'rgba(59, 130, 246, 0.10)'}
                stroke="none"
              />
            )}

            {/* Open Traverse Course Lines */}
            <polyline
              points={svgData.pointsStr}
              fill="none"
              stroke="#38bdf8"
              strokeWidth="1.8"
              strokeLinecap="round"
              strokeLinejoin="round"
            />

            {/* Red Dashed Closure Gap Line (between last vertex and POB) */}
            {closureGap > 0.005 && (
              <line
                x1={lastPt.easting}
                y1={-lastPt.northing}
                x2={firstPt.easting}
                y2={-firstPt.northing}
                stroke="#ef4444"
                strokeWidth="2"
                strokeDasharray="4 3"
              />
            )}

            {/* Vertex Nodes */}
            {vertices.map((v) => (
              <g key={v.seq} transform={`translate(${v.easting}, ${-v.northing})`}>
                <circle
                  r={v.seq === 0 ? '3.5' : '2.5'}
                  fill={v.seq === 0 ? '#fbbf24' : '#ffffff'}
                  stroke="#0284c7"
                  strokeWidth="1"
                />
                <text
                  x="4"
                  y="-4"
                  fill="#94a3b8"
                  fontSize="7"
                  fontFamily="monospace"
                  fontWeight="bold"
                >
                  {v.label}
                </text>
              </g>
            ))}
          </svg>
        )}

        {/* Legend Overlay */}
        <div className="absolute bottom-2 left-2 flex items-center gap-3 bg-slate-800/80 px-2.5 py-1 rounded text-[10px] text-slate-300 backdrop-blur-sm">
          <div className="flex items-center gap-1">
            <span className="w-2.5 h-0.5 bg-sky-400 inline-block"></span>
            <span>Traverse</span>
          </div>
          {closureGap > 0.005 && (
            <div className="flex items-center gap-1">
              <span className="w-2.5 h-0.5 border-b border-red-500 border-dashed inline-block"></span>
              <span className="text-red-300">Closure Gap</span>
            </div>
          )}
          <div className="flex items-center gap-1">
            <span className="w-2 h-2 rounded-full bg-amber-400 inline-block"></span>
            <span>POB</span>
          </div>
        </div>
      </div>
    </div>
  );
};
