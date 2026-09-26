import React, { useState, useEffect, useCallback } from 'react';
import {
  surveyApi,
  type TechnicalDescription,
  type CourseValidationResult,
  type StagedParseResult,
} from '../api/surveyApi';
import { BearingInput, type BearingValue } from './BearingInput';
import { TraversePreviewMap } from './TraversePreviewMap';

interface TechnicalDescriptionTabProps {
  parcelId: string;
}

export const TechnicalDescriptionTab: React.FC<TechnicalDescriptionTabProps> = ({ parcelId }) => {
  const [revisions, setRevisions] = useState<TechnicalDescription[]>([]);
  const [selectedTd, setSelectedTd] = useState<TechnicalDescription | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);

  // Parse review state
  const [showParseModal, setShowParseModal] = useState<boolean>(false);
  const [pastedText, setPastedText] = useState<string>('');
  const [parseLoading, setParseLoading] = useState<boolean>(false);
  const [parseResult, setParseResult] = useState<StagedParseResult | null>(null);
  const [confirmError, setConfirmError] = useState<string | null>(null);

  // Course addition state
  const [showAddCourse, setShowAddCourse] = useState<boolean>(false);
  const [newBearing, setNewBearing] = useState<BearingValue>({ quadrant: 'NE', deg: 45, min: 0, sec: 0 });
  const [newDistance, setNewDistance] = useState<string>('30.00');
  const [newUnit, setNewUnit] = useState<string>('m');
  const [newFrom, setNewFrom] = useState<string>('');
  const [newTo, setNewTo] = useState<string>('');

  // Course validation state
  const [validationResult, setValidationResult] = useState<CourseValidationResult | null>(null);
  const [validating, setValidating] = useState<boolean>(false);

  // Load revisions
  const loadRevisions = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const list = await surveyApi.listByParcel(parcelId);
      setRevisions(list);

      if (list.length > 0) {
        // Default to current revision or newest
        const current = list.find((r) => r.is_current) || list[0];
        const detail = await surveyApi.getById(current.id);
        setSelectedTd(detail.data);
      } else {
        setSelectedTd(null);
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load technical descriptions');
    } finally {
      setLoading(false);
    }
  }, [parcelId]);

  useEffect(() => {
    loadRevisions();
  }, [loadRevisions]);

  const handleSelectRevision = async (tdId: number) => {
    try {
      setLoading(true);
      const detail = await surveyApi.getById(tdId);
      setSelectedTd(detail.data);
      setValidationResult(null);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load revision detail');
    } finally {
      setLoading(false);
    }
  };

  const handleCreateNewRevision = async () => {
    try {
      setLoading(true);
      const newTd = await surveyApi.createForParcel(parcelId, {
        source_type: 'MANUALLY_ENTERED',
        bearing_reference: selectedTd?.bearing_reference || 'GRID',
        distance_unit: selectedTd?.distance_unit || 'm',
      });
      await loadRevisions();
      setSelectedTd(newTd);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to create new revision');
      setLoading(false);
    }
  };

  const handleRunValidation = async () => {
    if (!selectedTd) return;
    try {
      setValidating(true);
      const res = await surveyApi.validateCourses(selectedTd.id);
      setValidationResult(res);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to run course validation');
    } finally {
      setValidating(false);
    }
  };

  const handleAddCourseSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedTd) return;

    const dVal = parseFloat(newDistance);
    if (isNaN(dVal) || dVal <= 0.01) {
      setError('Distance must be greater than 0.01 m');
      return;
    }

    try {
      setLoading(true);
      const updated = await surveyApi.addCourse(selectedTd.id, {
        from_point_label: newFrom || undefined,
        to_point_label: newTo || undefined,
        bearing: newBearing.bearingStr,
        quadrant: newBearing.quadrant || undefined,
        deg: newBearing.deg ?? undefined,
        min: newBearing.min ?? undefined,
        sec: newBearing.sec ?? undefined,
        distance: dVal,
        unit: newUnit,
      });
      setSelectedTd(updated);
      setShowAddCourse(false);
      setNewDistance('30.00');
      setValidationResult(null);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to add course');
    } finally {
      setLoading(false);
    }
  };

  const handleDeleteCourse = async (courseId: number) => {
    if (!selectedTd) return;
    if (!confirm('Are you sure you want to delete this course? Courses will be sequentially renumbered.')) {
      return;
    }
    try {
      setLoading(true);
      const updated = await surveyApi.deleteCourse(selectedTd.id, courseId);
      setSelectedTd(updated);
      setValidationResult(null);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to delete course');
    } finally {
      setLoading(false);
    }
  };

  const handleReorderCourse = async (courseId: number, direction: 'UP' | 'DOWN') => {
    if (!selectedTd || !selectedTd.courses) return;
    const courses = [...selectedTd.courses];
    const idx = courses.findIndex((c) => c.id === courseId);
    if (idx === -1) return;

    const targetIdx = direction === 'UP' ? idx - 1 : idx + 1;
    if (targetIdx < 0 || targetIdx >= courses.length) return;

    // Swap
    const temp = courses[idx];
    courses[idx] = courses[targetIdx];
    courses[targetIdx] = temp;

    const ids = courses.map((c) => c.id);
    try {
      setLoading(true);
      const updated = await surveyApi.reorderCourses(selectedTd.id, ids);
      setSelectedTd(updated);
      setValidationResult(null);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to reorder courses');
    } finally {
      setLoading(false);
    }
  };

  const handleParseTextSubmit = async () => {
    if (!pastedText.trim()) return;
    try {
      setParseLoading(true);
      setConfirmError(null);
      const res = await surveyApi.parseText({
        text: pastedText,
        source_type: 'PASTED_TEXT',
        distance_unit_hint: selectedTd?.distance_unit || 'm',
      });
      setParseResult(res);
    } catch (err: unknown) {
      setConfirmError(err instanceof Error ? err.message : 'Failed to parse technical description');
    } finally {
      setParseLoading(false);
    }
  };

  const handleApplyParsedToNewRevision = async () => {
    if (!parseResult) return;
    try {
      setLoading(true);
      const newTd = await surveyApi.createForParcel(parcelId, {
        original_text: pastedText,
        source_type: 'PASTED_TEXT',
        distance_unit: selectedTd?.distance_unit || 'm',
      });
      await loadRevisions();
      setSelectedTd(newTd);
      setShowParseModal(false);
      setParseResult(null);
      setPastedText('');
    } catch (err: unknown) {
      setConfirmError(err instanceof Error ? err.message : 'Failed to apply parsed courses');
    } finally {
      setLoading(false);
    }
  };

  const handleConfirmRevision = async () => {
    if (!selectedTd) return;
    try {
      setLoading(true);
      setError(null);
      const confirmed = await surveyApi.confirm(selectedTd.id);
      setSelectedTd(confirmed);
      await loadRevisions();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to confirm technical description');
    } finally {
      setLoading(false);
    }
  };

  if (loading && revisions.length === 0) {
    return (
      <div className="p-8 text-center text-sm text-gray-500">
        Loading technical descriptions...
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6 p-6">
      {/* Top Header & Revisions Bar */}
      <div className="flex flex-wrap items-center justify-between gap-4 bg-gray-50 p-4 rounded-lg border">
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-2">
            <span className="text-xs font-semibold uppercase text-gray-600">Revision:</span>
            {revisions.length > 0 ? (
              <select
                value={selectedTd?.id || ''}
                onChange={(e) => handleSelectRevision(Number(e.target.value))}
                className="border rounded px-2.5 py-1 text-sm font-semibold bg-white text-gray-800"
              >
                {revisions.map((r) => (
                  <option key={r.id} value={r.id}>
                    Rev {r.revision} {r.is_current ? '(Current)' : ''} — {r.parser_status}
                  </option>
                ))}
              </select>
            ) : (
              <span className="text-sm text-gray-400 italic">None</span>
            )}
          </div>

          {selectedTd && (
            <span
              className={`px-2.5 py-0.5 rounded-full text-xs font-semibold ${
                selectedTd.parser_status === 'CONFIRMED'
                  ? 'bg-green-100 text-green-800'
                  : selectedTd.parser_status === 'PARTIAL'
                  ? 'bg-amber-100 text-amber-800'
                  : 'bg-blue-100 text-blue-800'
              }`}
            >
              {selectedTd.parser_status}
            </span>
          )}
        </div>

        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setShowParseModal(true)}
            className="px-3 py-1.5 bg-indigo-600 text-white rounded text-xs font-medium hover:bg-indigo-700 shadow-sm flex items-center gap-1"
          >
            <span>📋 Paste & Parse</span>
          </button>
          <button
            type="button"
            onClick={handleCreateNewRevision}
            className="px-3 py-1.5 border border-gray-300 bg-white text-gray-700 rounded text-xs font-medium hover:bg-gray-50 shadow-sm"
          >
            + New Revision
          </button>
          {selectedTd && selectedTd.parser_status !== 'CONFIRMED' && (
            <button
              type="button"
              onClick={handleConfirmRevision}
              className="px-3.5 py-1.5 bg-green-600 text-white rounded text-xs font-semibold hover:bg-green-700 shadow-sm flex items-center gap-1"
              title="Confirm technical description for authoritative computation"
            >
              ✓ Confirm Description
            </button>
          )}
        </div>
      </div>

      {error && (
        <div className="p-3 bg-red-50 border border-red-200 text-red-700 rounded-md text-sm flex items-center justify-between">
          <span>⚠ {error}</span>
          <button type="button" onClick={() => setError(null)} className="text-red-500 font-bold">×</button>
        </div>
      )}

      {selectedTd && (
        <>
          {/* Metadata & Coordinate Settings */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-4 p-4 border rounded-lg bg-white shadow-sm text-xs">
            <div>
              <span className="text-gray-500 block mb-1">Bearing Reference:</span>
              <span className="font-semibold text-gray-800">{selectedTd.bearing_reference}</span>
            </div>
            <div>
              <span className="text-gray-500 block mb-1">Distance Unit:</span>
              <span className="font-semibold text-gray-800">{selectedTd.distance_unit}</span>
            </div>
            <div>
              <span className="text-gray-500 block mb-1">Point of Beginning (POB):</span>
              <span className="font-semibold text-gray-800">Point {selectedTd.point_of_beginning_label || '1'}</span>
            </div>
            <div>
              <span className="text-gray-500 block mb-1">Source Type:</span>
              <span className="font-mono text-gray-800">{selectedTd.source_type}</span>
            </div>
          </div>

          {/* Tie Point / Tie Line Section */}
          {selectedTd.tie_lines && selectedTd.tie_lines.length > 0 && (
            <div className="p-4 border rounded-lg bg-amber-50/50 border-amber-200 flex flex-col gap-2">
              <span className="text-xs font-semibold text-amber-900 uppercase tracking-wide">
                Tie Line Reference
              </span>
              <div className="flex items-center gap-4 text-xs text-gray-700">
                <span>
                  <strong>Bearing:</strong> {selectedTd.tie_lines[0].bearing.original || selectedTd.tie_lines[0].bearing.normalized}
                  {selectedTd.tie_lines[0].bearing.azimuth_dd !== null && ` (Az ${selectedTd.tie_lines[0].bearing.azimuth_dd.toFixed(4)}°)`}
                </span>
                <span>
                  <strong>Distance:</strong> {selectedTd.tie_lines[0].distance.meters?.toFixed(2) ?? selectedTd.tie_lines[0].distance.value} m
                </span>
                <span>
                  <strong>To POB:</strong> Point {selectedTd.tie_lines[0].to_point_label}
                </span>
              </div>
            </div>
          )}

          {/* Live Traverse Preview on Map (TASK-085) */}
          <TraversePreviewMap
            courses={selectedTd.courses || []}
            pobLabel={selectedTd.point_of_beginning_label || '1'}
          />

          {/* Validation Banner (TASK-081) */}
          {validationResult && (
            <div
              className={`p-4 rounded-lg border text-xs flex flex-col gap-2 ${
                validationResult.valid ? 'bg-green-50 border-green-200 text-green-900' : 'bg-red-50 border-red-200 text-red-900'
              }`}
            >
              <div className="flex items-center justify-between font-semibold">
                <span>
                  {validationResult.valid
                    ? '✓ Syntax Validation Passed: All courses conform to VR-01...VR-09'
                    : `⚠ Validation Failed: ${validationResult.errors.length} error(s) detected`}
                </span>
                <span className="text-[11px] font-normal text-gray-600">
                  {validationResult.warnings.length} warning(s)
                </span>
              </div>

              {validationResult.errors.map((err, i) => (
                <div key={i} className="flex items-center gap-2 text-red-700">
                  <span className="px-1.5 py-0.5 rounded font-bold bg-red-200 text-[10px]">{err.rule}</span>
                  <span>Course #{err.course_seq}: {err.message}</span>
                </div>
              ))}

              {validationResult.warnings.map((warn, i) => (
                <div key={i} className="flex items-center gap-2 text-amber-700">
                  <span className="px-1.5 py-0.5 rounded font-bold bg-amber-200 text-[10px]">{warn.rule}</span>
                  <span>Course #{warn.course_seq}: {warn.message}</span>
                </div>
              ))}
            </div>
          )}

          {/* Course Table Section */}
          <div className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
              <span className="font-semibold text-sm text-gray-800">
                Courses ({selectedTd.courses?.length || 0})
              </span>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={handleRunValidation}
                  disabled={validating}
                  className="px-3 py-1 bg-gray-100 text-gray-700 border rounded text-xs font-medium hover:bg-gray-200"
                >
                  {validating ? 'Validating...' : '🔍 Validate Courses'}
                </button>
                {selectedTd.parser_status !== 'CONFIRMED' && (
                  <button
                    type="button"
                    onClick={() => setShowAddCourse(true)}
                    className="px-3 py-1 bg-blue-600 text-white rounded text-xs font-semibold hover:bg-blue-700"
                  >
                    + Add Course
                  </button>
                )}
              </div>
            </div>

            {/* Courses Table */}
            <div className="border rounded-lg overflow-x-auto shadow-sm">
              <table className="w-full text-xs text-left">
                <thead className="bg-gray-50 border-b text-gray-600 uppercase font-semibold text-[11px]">
                  <tr>
                    <th className="p-2.5 w-12 text-center">#</th>
                    <th className="p-2.5 w-16">From</th>
                    <th className="p-2.5 w-16">To</th>
                    <th className="p-2.5">Bearing (Quadrant / Azimuth)</th>
                    <th className="p-2.5 w-28">Distance</th>
                    <th className="p-2.5 w-16">Unit</th>
                    <th className="p-2.5 w-24">Confidence</th>
                    <th className="p-2.5">Remarks</th>
                    {selectedTd.parser_status !== 'CONFIRMED' && (
                      <th className="p-2.5 w-24 text-right">Actions</th>
                    )}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {selectedTd.courses && selectedTd.courses.length > 0 ? (
                    selectedTd.courses.map((c, index) => (
                      <tr key={c.id} className="hover:bg-gray-50">
                        <td className="p-2.5 text-center font-bold text-gray-700">{c.seq}</td>
                        <td className="p-2.5 font-mono">{c.from_point_label}</td>
                        <td className="p-2.5 font-mono">{c.to_point_label}</td>
                        <td className="p-2.5">
                          <div className="flex items-center gap-2">
                            <span className="font-semibold text-gray-800">
                              {c.bearing.original || c.bearing.normalized || 'N/A'}
                            </span>
                            {c.bearing.azimuth_dd !== null && (
                              <span className="text-gray-400 font-mono text-[11px]">
                                ({c.bearing.azimuth_dd.toFixed(4)}°)
                              </span>
                            )}
                          </div>
                        </td>
                        <td className="p-2.5 font-mono font-medium text-gray-900">
                          {c.distance.meters?.toFixed(2) ?? c.distance.value?.toFixed(2) ?? '—'}
                        </td>
                        <td className="p-2.5 text-gray-500">{c.distance.unit}</td>
                        <td className="p-2.5">
                          {c.confidence !== null ? (
                            <span
                              className={`flex items-center gap-1 font-semibold ${
                                c.confidence >= 0.8 ? 'text-green-600' : 'text-amber-600'
                              }`}
                            >
                              <span>{c.confidence >= 0.8 ? '●' : '▲'}</span>
                              <span>{(c.confidence * 100).toFixed(0)}%</span>
                            </span>
                          ) : (
                            <span className="text-gray-400">—</span>
                          )}
                        </td>
                        <td className="p-2.5 text-gray-500">{c.remarks || '—'}</td>
                        {selectedTd.parser_status !== 'CONFIRMED' && (
                          <td className="p-2.5 text-right">
                            <div className="flex items-center justify-end gap-1">
                              <button
                                type="button"
                                disabled={index === 0}
                                onClick={() => handleReorderCourse(c.id, 'UP')}
                                className="px-1 py-0.5 text-gray-500 hover:text-gray-800 disabled:opacity-30"
                                title="Move Course Up"
                              >
                                ▲
                              </button>
                              <button
                                type="button"
                                disabled={index === (selectedTd.courses?.length || 0) - 1}
                                onClick={() => handleReorderCourse(c.id, 'DOWN')}
                                className="px-1 py-0.5 text-gray-500 hover:text-gray-800 disabled:opacity-30"
                                title="Move Course Down"
                              >
                                ▼
                              </button>
                              <button
                                type="button"
                                onClick={() => handleDeleteCourse(c.id)}
                                className="px-1 py-0.5 text-red-500 hover:text-red-700"
                                title="Delete Course"
                              >
                                🗑
                              </button>
                            </div>
                          </td>
                        )}
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan={9} className="p-6 text-center text-gray-400">
                        No courses added yet. Paste text or click "+ Add Course" to populate.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}

      {/* Add Course Modal */}
      {showAddCourse && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-lg w-full p-5 flex flex-col gap-4">
            <h3 className="font-semibold text-base text-gray-800 border-b pb-2">
              Add Survey Course
            </h3>
            <form onSubmit={handleAddCourseSubmit} className="flex flex-col gap-4">
              <div className="grid grid-cols-2 gap-3">
                <div className="form-group">
                  <label className="form-label">From Point Label</label>
                  <input
                    type="text"
                    value={newFrom}
                    onChange={(e) => setNewFrom(e.target.value)}
                    placeholder="e.g. 1"
                    className="form-input"
                  />
                </div>
                <div className="form-group">
                  <label className="form-label">To Point Label</label>
                  <input
                    type="text"
                    value={newTo}
                    onChange={(e) => setNewTo(e.target.value)}
                    placeholder="e.g. 2"
                    className="form-input"
                  />
                </div>
              </div>

              <div className="form-group">
                <label className="form-label">Bearing</label>
                <BearingInput
                  value={newBearing}
                  onChange={(val) => setNewBearing(val)}
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="form-group">
                  <label className="form-label">Distance</label>
                  <input
                    type="number"
                    step="0.01"
                    value={newDistance}
                    onChange={(e) => setNewDistance(e.target.value)}
                    className="form-input text-right font-mono"
                    required
                  />
                </div>
                <div className="form-group">
                  <label className="form-label">Unit</label>
                  <select
                    value={newUnit}
                    onChange={(e) => setNewUnit(e.target.value)}
                    className="form-select"
                  >
                    <option value="m">m (Meters)</option>
                    <option value="ft">ft (Feet)</option>
                    <option value="vara">vara (Spanish vara)</option>
                    <option value="ch">ch (Chain)</option>
                  </select>
                </div>
              </div>

              <div className="flex justify-end gap-2 pt-2 border-t mt-2">
                <button
                  type="button"
                  onClick={() => setShowAddCourse(false)}
                  className="px-3 py-1.5 border rounded text-xs text-gray-600 hover:bg-gray-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-1.5 bg-blue-600 text-white rounded text-xs font-semibold hover:bg-blue-700"
                >
                  Save Course
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Paste & Parse Modal (TASK-082, 083, 084) */}
      {showParseModal && (
        <div className="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
          <div className="bg-white rounded-xl shadow-2xl max-w-4xl w-full p-6 flex flex-col gap-4 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between border-b pb-2">
              <h3 className="font-bold text-base text-gray-900">
                Paste Technical Description & Review (TASK-084)
              </h3>
              <button
                type="button"
                onClick={() => setShowParseModal(false)}
                className="text-gray-400 hover:text-gray-600 text-lg font-bold"
              >
                ×
              </button>
            </div>

            {confirmError && (
              <div className="p-3 bg-red-50 border border-red-200 text-red-700 rounded text-xs">
                ⚠ {confirmError}
              </div>
            )}

            <div className="flex flex-col gap-2">
              <label className="form-label text-xs">
                Paste Technical Description Text:
              </label>
              <textarea
                rows={5}
                value={pastedText}
                onChange={(e) => setPastedText(e.target.value)}
                placeholder="e.g. Beginning at a point marked 1 on plan, being S. 45 deg. 12' E., 120.50 m. from BLLM No. 1; thence N. 25 deg. 30' E., 45.20 m. to point 2; thence S. 64 deg. 30' E., 30.00 m. to point 3..."
                className="form-textarea font-mono text-xs"
              />
              <div className="flex justify-end">
                <button
                  type="button"
                  disabled={parseLoading || !pastedText.trim()}
                  onClick={handleParseTextSubmit}
                  className="px-4 py-2 bg-indigo-600 text-white rounded-md text-xs font-semibold hover:bg-indigo-700 disabled:opacity-50"
                >
                  {parseLoading ? 'Parsing...' : 'Parse Description'}
                </button>
              </div>
            </div>

            {/* Parsed Review Pane */}
            {parseResult && (
              <div className="flex flex-col gap-3 border-t pt-4">
                <div className="flex items-center justify-between">
                  <span className="font-semibold text-sm text-gray-800">
                    Parsed Result — {parseResult.courses.length} courses extracted
                  </span>
                  <span
                    className={`px-2 py-0.5 rounded text-xs font-semibold ${
                      parseResult.parser_status === 'PARSED'
                        ? 'bg-green-100 text-green-800'
                        : 'bg-amber-100 text-amber-800'
                    }`}
                  >
                    {parseResult.parser_status}
                  </span>
                </div>

                {parseResult.tie_point_name && (
                  <div className="text-xs text-gray-700 bg-amber-50 p-2.5 rounded border border-amber-200">
                    <strong>Tie Point Identified:</strong> {parseResult.tie_point_name}
                  </div>
                )}

                <div className="max-h-60 overflow-y-auto border rounded-lg divide-y text-xs">
                  {parseResult.courses.map((c) => (
                    <div
                      key={c.seq}
                      className={`p-2.5 flex items-center justify-between ${
                        !c.resolved ? 'bg-amber-50/70' : 'hover:bg-gray-50'
                      }`}
                    >
                      <div className="flex items-center gap-3">
                        <span className="font-bold text-gray-600 w-8">#{c.seq}</span>
                        <span className="font-semibold text-gray-800">
                          {c.bearing ? `${c.bearing.quadrant} ${c.bearing.deg}°${c.bearing.min}'${c.bearing.sec}"` : '⚠ Unresolved Bearing'}
                        </span>
                        <span className="font-mono text-gray-700">
                          {c.distance ? `${c.distance.value?.toFixed(2)} ${c.distance.unit}` : '⚠ Missing Distance'}
                        </span>
                      </div>

                      <div className="flex items-center gap-3">
                        <span
                          className={`font-semibold flex items-center gap-1 ${
                            c.confidence >= 0.8 ? 'text-green-600' : 'text-amber-600'
                          }`}
                        >
                          <span>{c.confidence >= 0.8 ? '● high' : '▲ low'}</span>
                          <span>({(c.confidence * 100).toFixed(0)}%)</span>
                        </span>
                        {!c.resolved && (
                          <span className="text-red-600 font-medium">Needs Attention</span>
                        )}
                      </div>
                    </div>
                  ))}
                </div>

                <div className="flex items-center justify-between pt-3 border-t">
                  <div className="text-xs text-gray-500">
                    {parseResult.parser_status === 'PARTIAL' && (
                      <span className="text-amber-700 font-semibold">
                        ⚠ Some courses need attention before confirmation.
                      </span>
                    )}
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      type="button"
                      onClick={() => setParseResult(null)}
                      className="px-3 py-1.5 border rounded text-xs text-gray-600 hover:bg-gray-50"
                    >
                      Discard
                    </button>
                    <button
                      type="button"
                      onClick={handleApplyParsedToNewRevision}
                      className="px-4 py-1.5 bg-green-600 text-white rounded text-xs font-semibold hover:bg-green-700"
                    >
                      Apply as New Revision
                    </button>
                  </div>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};
