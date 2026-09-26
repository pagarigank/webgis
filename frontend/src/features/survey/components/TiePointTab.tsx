import React, { useState, useEffect, useCallback } from 'react';
import { surveyApi, type TechnicalDescription } from '../api/surveyApi';
import { ControlPointStatusBadge } from '../../control-points/components/ControlPointStatusBadge';
import { ControlPointPicker } from '../../control-points/components/ControlPointPicker';
import type { ControlPoint } from '../../control-points/api/controlPointApi';

interface TiePointTabProps {
  parcelId: string;
}

export const TiePointTab: React.FC<TiePointTabProps> = ({ parcelId }) => {
  const [currentTd, setCurrentTd] = useState<TechnicalDescription | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [showPicker, setShowPicker] = useState<boolean>(false);

  const loadData = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const list = await surveyApi.listByParcel(parcelId);
      if (list.length > 0) {
        const cur = list.find((r) => r.is_current) || list[0];
        const detail = await surveyApi.getById(cur.id);
        setCurrentTd(detail.data);
      } else {
        setCurrentTd(null);
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load tie point data');
    } finally {
      setLoading(false);
    }
  }, [parcelId]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const handleSelectControlPoint = async (cp: ControlPoint) => {
    if (!currentTd) return;
    try {
      setLoading(true);
      setError(null);
      
      const tiePoints = currentTd.tie_points || [];
      if (tiePoints.length > 0) {
        await surveyApi.linkTiePoint(currentTd.id, tiePoints[0].id, cp.id);
      } else {
        await surveyApi.createTiePoint(currentTd.id, cp.id);
      }
      
      setShowPicker(false);
      await loadData();
    } catch (err: any) {
      setError(err?.message || 'Failed to link control point');
      setLoading(false);
    }
  };

  if (loading) {
    return <div className="p-8 text-center text-sm text-gray-500">Loading tie point data...</div>;
  }

  const tiePoints = currentTd?.tie_points || [];
  const tieLines = currentTd?.tie_lines || [];

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex items-center justify-between border-b pb-3">
        <div>
          <h2 className="text-sm font-bold text-gray-800 uppercase tracking-wide">
            Survey Tie Point & Tie Lines
          </h2>
          <p className="text-xs text-gray-500 mt-0.5">
            Geodetic reference monument anchoring the parcel's point of beginning (POB).
          </p>
        </div>
        <button
          type="button"
          onClick={() => setShowPicker(!showPicker)}
          disabled={!currentTd}
          title={!currentTd ? 'Add a technical description first' : ''}
          className={`btn btn-sm ${
            !currentTd
              ? 'btn-secondary'
              : 'btn-primary'
          }`}
        >
          {showPicker ? 'Close Picker' : '🔍 Find Control Point'}
        </button>
      </div>

      {error && (
        <div className="p-3 bg-red-50 border border-red-200 text-red-700 rounded text-xs">
          ⚠ {error}
        </div>
      )}

      {showPicker && (
        <div className="p-4 bg-gray-50 border rounded-lg">
          <h3 className="text-xs font-semibold text-gray-700 mb-2">Select Reference Control Point:</h3>
          <ControlPointPicker onSelect={handleSelectControlPoint} />
        </div>
      )}

      {/* Tie Point Summary Cards */}
      {tiePoints.length > 0 ? (
        tiePoints.map((tp) => (
          <div key={tp.id} className="border rounded-lg p-4 bg-white shadow-sm flex flex-col gap-3">
            <div className="flex items-center justify-between border-b pb-2">
              <div className="flex items-center gap-2">
                <span className="font-bold text-sm text-gray-900">
                  {tp.adhoc_name || `Control Point #${tp.control_point_id}`}
                </span>
                <span className="text-xs px-2 py-0.5 bg-gray-100 text-gray-700 rounded font-medium">
                  Role: {tp.role}
                </span>
              </div>
              <ControlPointStatusBadge status={tp.as_used_status} />
            </div>

            <div className="grid grid-cols-2 md:grid-cols-3 gap-4 text-xs">
              <div>
                <span className="text-gray-500 block">As-Used Easting:</span>
                <span className="font-mono font-semibold text-gray-800">
                  {Number(tp.as_used_easting).toFixed(4)} m
                </span>
              </div>
              <div>
                <span className="text-gray-500 block">As-Used Northing:</span>
                <span className="font-mono font-semibold text-gray-800">
                  {Number(tp.as_used_northing).toFixed(4)} m
                </span>
              </div>
              <div>
                <span className="text-gray-500 block">Sequence:</span>
                <span className="font-semibold text-gray-800">#{tp.sequence}</span>
              </div>
            </div>

            {/* Tie Lines for this tie point */}
            <div className="mt-2 border-t pt-2">
              <span className="text-xs font-semibold text-gray-700 block mb-1">
                Tie Line(s) to Point of Beginning:
              </span>
              {tieLines.length > 0 ? (
                <div className="border rounded divide-y text-xs">
                  {tieLines.map((tl) => (
                    <div key={tl.id} className="p-2 flex items-center justify-between">
                      <span className="font-semibold text-gray-800">
                        Bearing: {tl.bearing.original || tl.bearing.normalized || 'N/A'}
                        {tl.bearing.azimuth_dd !== null && ` (Az ${tl.bearing.azimuth_dd.toFixed(4)}°)`}
                      </span>
                      <span className="font-mono text-gray-700">
                        Distance: {tl.distance.meters?.toFixed(2) ?? tl.distance.value} m
                      </span>
                      <span className="text-gray-500">
                        Target: Point {tl.to_point_label}
                      </span>
                    </div>
                  ))}
                </div>
              ) : (
                <span className="text-xs text-gray-400 italic">No tie lines recorded.</span>
              )}
            </div>
          </div>
        ))
      ) : (
        <div className="p-8 text-center bg-gray-50 border border-dashed rounded-lg text-gray-400 text-xs">
          No tie points linked to this parcel's current technical description.
        </div>
      )}
    </div>
  );
};
