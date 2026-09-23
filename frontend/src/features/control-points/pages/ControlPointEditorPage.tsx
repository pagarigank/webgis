import React, { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { controlPointApi } from '../api/controlPointApi';
import type { ControlPoint, ControlPointPayload, DependentParcel } from '../api/controlPointApi';
import { ControlPointStatusBadge } from '../components/ControlPointStatusBadge';
import { useAuth } from '../../../auth/useAuth';
import { hasPermission } from '../../../auth/permissions';

export const ControlPointEditorPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const isNew = !id || id === 'new';
  const navigate = useNavigate();
  const { me } = useAuth();

  const [loading, setLoading] = useState<boolean>(!isNew);
  const [saving, setSaving] = useState<boolean>(false);
  const [verifying, setVerifying] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  // Form state
  const [pointName, setPointName] = useState<string>('');
  const [pointType, setPointType] = useState<string>('BLLM');
  const [monumentType, setMonumentType] = useState<string>('Concrete monument');
  const [nativeCrs, setNativeCrs] = useState<string>('EPSG:3123');
  const [coordinateOrigin, setCoordinateOrigin] = useState<'PROJECTED' | 'GEOGRAPHIC'>('PROJECTED');
  const [easting, setEasting] = useState<string>('');
  const [northing, setNorthing] = useState<string>('');
  const [latitude, setLatitude] = useState<string>('');
  const [longitude, setLongitude] = useState<string>('');
  const [elevation, setElevation] = useState<string>('');
  const [source, setSource] = useState<string>('');
  const [surveyRef, setSurveyRef] = useState<string>('');
  const [accuracyClass, setAccuracyClass] = useState<string>('2nd order');
  const [accuracyValueM, setAccuracyValueM] = useState<string>('0.05');
  const [description, setDescription] = useState<string>('');
  const [status, setStatus] = useState<string>('UNVERIFIED');
  const [psgcBarangay, setPsgcBarangay] = useState<string>('');
  const [changeReason, setChangeReason] = useState<string>('');

  // Existing point metadata & dependents
  const [point, setPoint] = useState<ControlPoint | null>(null);
  const [dependents, setDependents] = useState<DependentParcel[]>([]);

  const canEdit = hasPermission(me, 'control_point.update') || (isNew && hasPermission(me, 'control_point.create'));
  const canVerify = hasPermission(me, 'control_point.verify');

  const loadPointData = useCallback(async () => {
    if (isNew) return;
    setLoading(true);
    setError(null);
    try {
      const data = await controlPointApi.getById(id!);
      setPoint(data);
      setPointName(data.point_name);
      setPointType(data.point_type);
      setMonumentType(data.monument_type || '');
      setNativeCrs(data.native_crs || 'EPSG:3123');
      setCoordinateOrigin(data.coordinate_origin);
      setEasting(data.easting !== null ? data.easting.toString() : '');
      setNorthing(data.northing !== null ? data.northing.toString() : '');
      setLatitude(data.latitude !== null ? data.latitude.toString() : '');
      setLongitude(data.longitude !== null ? data.longitude.toString() : '');
      setElevation(data.elevation !== null ? data.elevation.toString() : '');
      setSource(data.source || '');
      setSurveyRef(data.survey_reference || '');
      setAccuracyClass(data.accuracy_class || '');
      setAccuracyValueM(data.accuracy_value_m !== null ? data.accuracy_value_m.toString() : '');
      setDescription(data.description || '');
      setStatus(data.status);
      setPsgcBarangay(data.psgc_barangay || '');

      // Load dependents
      const depRes = await controlPointApi.getDependents(id!);
      setDependents(depRes.dependents);
    } catch (err: any) {
      setError(err?.message || 'Failed to load control point details.');
    } finally {
      setLoading(false);
    }
  }, [id, isNew]);

  useEffect(() => {
    loadPointData();
  }, [loadPointData]);

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!pointName.trim()) {
      setError('Point Name is required.');
      return;
    }
    setSaving(true);
    setError(null);
    setSuccess(null);

    const payload: ControlPointPayload = {
      point_name: pointName.trim(),
      point_type: pointType,
      monument_type: monumentType || null,
      native_crs: nativeCrs,
      coordinate_origin: coordinateOrigin,
      easting: coordinateOrigin === 'PROJECTED' ? parseFloat(easting) || null : null,
      northing: coordinateOrigin === 'PROJECTED' ? parseFloat(northing) || null : null,
      latitude: coordinateOrigin === 'GEOGRAPHIC' ? parseFloat(latitude) || null : null,
      longitude: coordinateOrigin === 'GEOGRAPHIC' ? parseFloat(longitude) || null : null,
      elevation: elevation ? parseFloat(elevation) : null,
      source: source || null,
      survey_reference: surveyRef || null,
      accuracy_class: accuracyClass || null,
      accuracy_value_m: accuracyValueM ? parseFloat(accuracyValueM) : null,
      description: description || null,
      psgc_barangay: psgcBarangay || null,
      change_reason: changeReason || undefined,
    };

    try {
      if (isNew) {
        const created = await controlPointApi.create(payload);
        setSuccess('Control point created successfully.');
        setTimeout(() => navigate(`/control-points/${created.id}`), 1000);
      } else {
        const updated = await controlPointApi.update(id!, payload, point!.version);
        setPoint(updated);
        setSuccess('Control point updated successfully.');
        setChangeReason('');
        loadPointData();
      }
    } catch (err: any) {
      setError(err?.response?.data?.error?.message || err?.message || 'Save failed.');
    } finally {
      setSaving(false);
    }
  };

  const handleVerify = async () => {
    if (!id || isNew) return;
    setVerifying(true);
    setError(null);
    try {
      const updated = await controlPointApi.verify(id, 'Verified by authorised surveyor');
      setPoint(updated);
      setStatus(updated.status);
      setSuccess('Control point has been marked VERIFIED.');
    } catch (err: any) {
      setError(err?.response?.data?.error?.message || err?.message || 'Verification failed.');
    } finally {
      setVerifying(false);
    }
  };

  if (loading) {
    return <div style={{ padding: '32px', textAlign: 'center' }}>Loading control point...</div>;
  }

  return (
    <div style={{ padding: '24px', maxWidth: '1100px', margin: '0 auto' }}>
      <div style={{ marginBottom: '16px' }}>
        <Link to="/control-points" style={{ color: '#0056b3', textDecoration: 'none', fontSize: '0.9rem' }}>
          ← Back to Control Points List
        </Link>
      </div>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '24px' }}>
        <div>
          <h1 style={{ fontSize: '1.75rem', fontWeight: 700, margin: 0, color: '#212529' }}>
            {isNew ? 'New Control Point' : `Control Point: ${point?.point_name}`}
          </h1>
          <p style={{ margin: '4px 0 0', color: '#6c757d', fontSize: '0.9rem' }}>
            {isNew ? 'Register a new geodetic monument' : `Version ${point?.version} • Status: ${status}`}
          </p>
        </div>
        {!isNew && (
          <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
            <ControlPointStatusBadge status={status} size="lg" />
            {status === 'UNVERIFIED' && canVerify && (
              <button
                type="button"
                onClick={handleVerify}
                disabled={verifying}
                style={{
                  padding: '8px 16px',
                  backgroundColor: '#28a745',
                  color: '#fff',
                  border: 'none',
                  borderRadius: '4px',
                  fontWeight: 600,
                  cursor: 'pointer',
                }}
              >
                {verifying ? 'Verifying…' : '✓ Verify Point'}
              </button>
            )}
          </div>
        )}
      </div>

      {error && (
        <div style={{ padding: '12px', backgroundColor: '#f8d7da', color: '#721c24', borderRadius: '4px', marginBottom: '16px' }}>
          {error}
        </div>
      )}
      {success && (
        <div style={{ padding: '12px', backgroundColor: '#d4edda', color: '#155724', borderRadius: '4px', marginBottom: '16px' }}>
          {success}
        </div>
      )}

      <form onSubmit={handleSave}>
        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr', gap: '24px' }}>
          {/* Main Info */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
            <div style={{ backgroundColor: '#fff', padding: '20px', borderRadius: '8px', border: '1px solid #dee2e6' }}>
              <h3 style={{ fontSize: '1.1rem', marginTop: 0, marginBottom: '16px', borderBottom: '1px solid #eee', paddingBottom: '8px' }}>
                Identity & Classification
              </h3>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>
                <div>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                    Point Name *
                  </label>
                  <input
                    type="text"
                    required
                    value={pointName}
                    onChange={(e) => setPointName(e.target.value)}
                    style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                    placeholder="e.g. BLLM-1, MBM-4"
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                    Point Type *
                  </label>
                  <select
                    value={pointType}
                    onChange={(e) => setPointType(e.target.value)}
                    style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                  >
                    <option value="BLLM">BLLM (Barangay/Bureau Location Monument)</option>
                    <option value="MBM">MBM (Municipal Boundary Monument)</option>
                    <option value="PBM">PBM (Provincial Boundary Monument)</option>
                    <option value="GCP">GCP (Ground Control Point)</option>
                    <option value="CONTROL_POINT">CONTROL_POINT</option>
                    <option value="TIE_POINT">TIE_POINT</option>
                    <option value="REFERENCE_POINT">REFERENCE_POINT</option>
                    <option value="OTHER">OTHER</option>
                  </select>
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                    Monument Physical Type
                  </label>
                  <input
                    type="text"
                    value={monumentType}
                    onChange={(e) => setMonumentType(e.target.value)}
                    style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                    placeholder="e.g. Concrete post, Brass pin in rock"
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                    PSGC Barangay Code
                  </label>
                  <input
                    type="text"
                    value={psgcBarangay}
                    onChange={(e) => setPsgcBarangay(e.target.value)}
                    style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                    placeholder="10-digit PSGC code"
                  />
                </div>
              </div>
            </div>

            <div style={{ backgroundColor: '#fff', padding: '20px', borderRadius: '8px', border: '1px solid #dee2e6' }}>
              <h3 style={{ fontSize: '1.1rem', marginTop: 0, marginBottom: '16px', borderBottom: '1px solid #eee', paddingBottom: '8px' }}>
                Spatial Coordinates & Reference System
              </h3>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px', marginBottom: '16px' }}>
                <div>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                    Native CRS *
                  </label>
                  <select
                    value={nativeCrs}
                    onChange={(e) => setNativeCrs(e.target.value)}
                    style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                  >
                    <option value="EPSG:3121">PRS92 / Philippines Zone I (EPSG:3121)</option>
                    <option value="EPSG:3122">PRS92 / Philippines Zone II (EPSG:3122)</option>
                    <option value="EPSG:3123">PRS92 / Philippines Zone III (EPSG:3123)</option>
                    <option value="EPSG:3124">PRS92 / Philippines Zone IV (EPSG:3124)</option>
                    <option value="EPSG:3125">PRS92 / Philippines Zone V (EPSG:3125)</option>
                    <option value="EPSG:25391">Luzon 1911 / Zone I (EPSG:25391)</option>
                    <option value="EPSG:25392">Luzon 1911 / Zone II (EPSG:25392)</option>
                    <option value="EPSG:25393">Luzon 1911 / Zone III (EPSG:25393)</option>
                    <option value="EPSG:25394">Luzon 1911 / Zone IV (EPSG:25394)</option>
                    <option value="EPSG:25395">Luzon 1911 / Zone V (EPSG:25395)</option>
                  </select>
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                    Coordinate Origin (Input Mode)
                  </label>
                  <select
                    value={coordinateOrigin}
                    onChange={(e) => setCoordinateOrigin(e.target.value as any)}
                    style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                  >
                    <option value="PROJECTED">Projected (Easting, Northing)</option>
                    <option value="GEOGRAPHIC">Geographic (Latitude, Longitude)</option>
                  </select>
                </div>
              </div>

              {coordinateOrigin === 'PROJECTED' ? (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>
                  <div>
                    <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                      Easting (m) *
                    </label>
                    <input
                      type="number"
                      step="any"
                      required
                      value={easting}
                      onChange={(e) => setEasting(e.target.value)}
                      style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                    />
                  </div>
                  <div>
                    <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                      Northing (m) *
                    </label>
                    <input
                      type="number"
                      step="any"
                      required
                      value={northing}
                      onChange={(e) => setNorthing(e.target.value)}
                      style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                    />
                  </div>
                </div>
              ) : (
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' }}>
                  <div>
                    <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                      Latitude (DD) *
                    </label>
                    <input
                      type="number"
                      step="any"
                      required
                      value={latitude}
                      onChange={(e) => setLatitude(e.target.value)}
                      style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                    />
                  </div>
                  <div>
                    <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                      Longitude (DD) *
                    </label>
                    <input
                      type="number"
                      step="any"
                      required
                      value={longitude}
                      onChange={(e) => setLongitude(e.target.value)}
                      style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                    />
                  </div>
                </div>
              )}

              {!isNew && point && (
                <div style={{ marginTop: '16px', padding: '12px', backgroundColor: '#f8f9fa', borderRadius: '4px', fontSize: '0.85rem' }}>
                  <div style={{ fontWeight: 600, marginBottom: '4px' }}>Derived PostGIS Coordinates:</div>
                  <div>
                    WGS84 Lat/Lon:{' '}
                    <code>
                      {point.latitude?.toFixed(7)}°, {point.longitude?.toFixed(7)}°
                    </code>{' '}
                    {point.derived?.latitude && <span style={{ color: '#856404' }}>(derived by ST_Transform)</span>}
                  </div>
                  <div>
                    Grid Easting/Northing:{' '}
                    <code>
                      E: {point.easting?.toFixed(3)}, N: {point.northing?.toFixed(3)}
                    </code>{' '}
                    {point.derived?.easting && <span style={{ color: '#856404' }}>(derived by ST_Transform)</span>}
                  </div>
                </div>
              )}
            </div>

            <div style={{ backgroundColor: '#fff', padding: '20px', borderRadius: '8px', border: '1px solid #dee2e6' }}>
              <h3 style={{ fontSize: '1.1rem', marginTop: 0, marginBottom: '16px', borderBottom: '1px solid #eee', paddingBottom: '8px' }}>
                Survey Details & Quality
              </h3>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px', marginBottom: '16px' }}>
                <div>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                    Accuracy Class
                  </label>
                  <input
                    type="text"
                    value={accuracyClass}
                    onChange={(e) => setAccuracyClass(e.target.value)}
                    style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                    placeholder="e.g. 1st order, 2nd order"
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                    Accuracy Value (meters)
                  </label>
                  <input
                    type="number"
                    step="any"
                    value={accuracyValueM}
                    onChange={(e) => setAccuracyValueM(e.target.value)}
                    style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                    placeholder="0.05"
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                    Survey Source
                  </label>
                  <input
                    type="text"
                    value={source}
                    onChange={(e) => setSource(e.target.value)}
                    style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                    placeholder="e.g. NAMRIA 2021 Geodetic Network"
                  />
                </div>
                <div>
                  <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                    Survey Reference / Plan
                  </label>
                  <input
                    type="text"
                    value={surveyRef}
                    onChange={(e) => setSurveyRef(e.target.value)}
                    style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                    placeholder="e.g. Cad-123-D"
                  />
                </div>
              </div>
              <div>
                <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                  Description / Location Notes
                </label>
                <textarea
                  rows={3}
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                  placeholder="Physical description, landmark references, access instructions"
                />
              </div>
            </div>

            {!isNew && (
              <div style={{ backgroundColor: '#fff', padding: '20px', borderRadius: '8px', border: '1px solid #dee2e6' }}>
                <label style={{ display: 'block', fontSize: '0.85rem', fontWeight: 600, marginBottom: '4px' }}>
                  Change Reason (Audit Requirement)
                </label>
                <input
                  type="text"
                  value={changeReason}
                  onChange={(e) => setChangeReason(e.target.value)}
                  style={{ width: '100%', padding: '8px', border: '1px solid #ced4da', borderRadius: '4px' }}
                  placeholder="Describe why coordinates or attributes are being updated..."
                />
              </div>
            )}

            <div>
              <button
                type="submit"
                disabled={saving || !canEdit}
                style={{
                  padding: '10px 24px',
                  backgroundColor: '#007bff',
                  color: '#fff',
                  border: 'none',
                  borderRadius: '4px',
                  fontWeight: 600,
                  fontSize: '1rem',
                  cursor: canEdit ? 'pointer' : 'not-allowed',
                }}
              >
                {saving ? 'Saving…' : isNew ? 'Create Control Point' : 'Save Changes'}
              </button>
            </div>
          </div>

          {/* Sidebar: Dependents & Info */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: '16px' }}>
            {!isNew && (
              <div style={{ backgroundColor: '#fff', padding: '20px', borderRadius: '8px', border: '1px solid #dee2e6' }}>
                <h3 style={{ fontSize: '1rem', marginTop: 0, marginBottom: '12px', borderBottom: '1px solid #eee', paddingBottom: '8px' }}>
                  Dependent Parcels ({dependents.length})
                </h3>
                <p style={{ fontSize: '0.8rem', color: '#6c757d', margin: '0 0 12px' }}>
                  Parcels tied to this control point. Editing coordinates flags these parcels for technical review without changing past computations.
                </p>
                {dependents.length === 0 ? (
                  <div style={{ fontSize: '0.85rem', color: '#6c757d' }}>No parcels currently tied.</div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', maxHeight: '300px', overflowY: 'auto' }}>
                    {dependents.map((dep) => (
                      <div
                        key={dep.parcel_id}
                        style={{
                          padding: '8px',
                          border: '1px solid #eee',
                          borderRadius: '4px',
                          fontSize: '0.85rem',
                        }}
                      >
                        <div style={{ fontWeight: 600 }}>
                          <Link to={`/parcels/${dep.parcel_id}`} style={{ color: '#0056b3', textDecoration: 'none' }}>
                            {dep.lot_number || dep.parcel_code}
                          </Link>
                        </div>
                        <div style={{ fontSize: '0.75rem', color: '#6c757d' }}>
                          Status: {dep.status} • Tie Point: {dep.tie_point_name}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      </form>
    </div>
  );
};
