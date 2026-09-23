import React, { useState, useEffect } from 'react';
import { surveyPlanApi } from '../../survey-plans/api/surveyPlanApi';
import type { SurveyPlan } from '../../survey-plans/api/surveyPlanApi';
import { parcelApi } from '../api/parcelApi';
import type { Parcel } from '../types';

interface SurveyPlanTabProps {
  parcel: Parcel;
  onUpdated: () => void;
}

export const SurveyPlanTab: React.FC<SurveyPlanTabProps> = ({ parcel, onUpdated }) => {
  const [currentPlan, setCurrentPlan] = useState<SurveyPlan | null>(null);
  const [loading, setLoading] = useState<boolean>(false);
  const [linking, setLinking] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  // Search/link state
  const [search, setSearch] = useState<string>('');
  const [availablePlans, setAvailablePlans] = useState<SurveyPlan[]>([]);
  const [showSearch, setShowSearch] = useState<boolean>(false);

  useEffect(() => {
    if (parcel.survey_plan_id) {
      setLoading(true);
      surveyPlanApi
        .getById(parcel.survey_plan_id)
        .then((plan) => setCurrentPlan(plan))
        .catch((err) => setError(err?.message || 'Failed to load survey plan details.'))
        .finally(() => setLoading(false));
    } else {
      setCurrentPlan(null);
    }
  }, [parcel.survey_plan_id]);

  const handleSearchPlans = async () => {
    setError(null);
    try {
      const res = await surveyPlanApi.list({ q: search.trim() || undefined, limit: 10 });
      setAvailablePlans(res.data);
    } catch (err: any) {
      setError(err?.message || 'Failed to query survey plans.');
    }
  };

  const handleLinkPlan = async (planId: number | null) => {
    setLinking(true);
    setError(null);
    try {
      await parcelApi.update(
        parcel.id,
        {
          survey_plan_id: planId,
          change_reason: planId ? `Linked to survey plan ${planId}` : 'Unlinked survey plan',
        },
        parcel.version
      );
      setShowSearch(false);
      onUpdated();
    } catch (err: any) {
      setError(err?.response?.data?.error?.message || err?.message || 'Failed to update linked survey plan.');
    } finally {
      setLinking(false);
    }
  };

  return (
    <div data-testid="parcel-survey-tab" style={{ padding: '8px 0' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px' }}>
        <div>
          <h4 style={{ margin: '0 0 4px', fontSize: '1.1rem', fontWeight: 600 }}>Survey Plan Linkage</h4>
          <p style={{ margin: 0, fontSize: '0.85rem', color: '#6c757d' }}>
            Official cadastral or subdivision survey plan associated with this parcel.
          </p>
        </div>
        <div>
          <button
            type="button"
            onClick={() => {
              setShowSearch(!showSearch);
              if (!showSearch) handleSearchPlans();
            }}
            style={{
              padding: '6px 14px',
              backgroundColor: '#f8f9fa',
              border: '1px solid #ced4da',
              borderRadius: '4px',
              fontSize: '0.85rem',
              cursor: 'pointer',
              fontWeight: 500,
            }}
          >
            {showSearch ? 'Cancel' : currentPlan ? 'Change Survey Plan' : 'Link Survey Plan'}
          </button>
        </div>
      </div>

      {error && (
        <div style={{ padding: '10px 14px', backgroundColor: '#f8d7da', color: '#721c24', borderRadius: '4px', marginBottom: '16px', fontSize: '0.9rem' }}>
          {error}
        </div>
      )}

      {loading ? (
        <div style={{ padding: '24px', textAlign: 'center', color: '#6c757d' }}>Loading survey plan…</div>
      ) : currentPlan ? (
        <div
          style={{
            border: '1px solid #dee2e6',
            borderRadius: '6px',
            padding: '16px',
            backgroundColor: '#fdfdfe',
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
            <div>
              <span style={{ fontSize: '0.8rem', backgroundColor: '#e7f1ff', color: '#0d6efd', padding: '2px 8px', borderRadius: '4px', fontWeight: 600 }}>
                {currentPlan.plan_type}
              </span>
              <h3 style={{ margin: '8px 0 4px', fontSize: '1.25rem', color: '#212529' }}>
                {currentPlan.plan_number}
              </h3>
            </div>
            <button
              type="button"
              disabled={linking}
              onClick={() => handleLinkPlan(null)}
              style={{
                background: 'none',
                border: 'none',
                color: '#dc3545',
                fontSize: '0.85rem',
                cursor: 'pointer',
                textDecoration: 'underline',
              }}
            >
              Unlink
            </button>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '16px', marginTop: '16px', fontSize: '0.85rem' }}>
            <div>
              <span style={{ color: '#6c757d', display: 'block' }}>Surveyor:</span>
              <strong>{currentPlan.surveyor_name || '—'}</strong>
              {currentPlan.surveyor_license && (
                <div style={{ fontSize: '0.75rem', color: '#6c757d' }}>Lic. {currentPlan.surveyor_license}</div>
              )}
            </div>
            <div>
              <span style={{ color: '#6c757d', display: 'block' }}>Approving Agency:</span>
              <strong>{currentPlan.approving_agency || '—'}</strong>
            </div>
            <div>
              <span style={{ color: '#6c757d', display: 'block' }}>Survey / Approval Date:</span>
              <strong>{currentPlan.survey_date || '—'}</strong> / <strong>{currentPlan.approved_date || '—'}</strong>
            </div>
            <div>
              <span style={{ color: '#6c757d', display: 'block' }}>CRS & Control Reference:</span>
              <strong>{currentPlan.crs_code || '—'}</strong>
              {currentPlan.control_reference && (
                <div style={{ fontSize: '0.75rem', color: '#6c757d' }}>Ref: {currentPlan.control_reference}</div>
              )}
            </div>
          </div>
        </div>
      ) : (
        <div
          style={{
            border: '2px dashed #dee2e6',
            borderRadius: '6px',
            padding: '32px',
            textAlign: 'center',
            color: '#6c757d',
          }}
        >
          <p style={{ margin: '0 0 12px', fontSize: '0.95rem' }}>
            No survey plan currently linked to this parcel.
          </p>
          <button
            type="button"
            onClick={() => {
              setShowSearch(true);
              handleSearchPlans();
            }}
            style={{
              padding: '8px 18px',
              backgroundColor: '#0d6efd',
              color: '#fff',
              border: 'none',
              borderRadius: '4px',
              fontWeight: 600,
              fontSize: '0.9rem',
              cursor: 'pointer',
            }}
          >
            Find & Link Survey Plan
          </button>
        </div>
      )}

      {showSearch && (
        <div
          style={{
            marginTop: '20px',
            border: '1px solid #ced4da',
            borderRadius: '6px',
            padding: '16px',
            backgroundColor: '#fff',
          }}
        >
          <h5 style={{ margin: '0 0 12px', fontSize: '1rem', fontWeight: 600 }}>Select Survey Plan to Link</h5>
          <div style={{ display: 'flex', gap: '8px', marginBottom: '12px' }}>
            <input
              type="text"
              placeholder="Search plan number, surveyor, or agency..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleSearchPlans()}
              style={{ flex: 1, padding: '6px 10px', border: '1px solid #ccc', borderRadius: '4px', fontSize: '0.85rem' }}
            />
            <button
              type="button"
              onClick={handleSearchPlans}
              style={{
                padding: '6px 14px',
                backgroundColor: '#0d6efd',
                color: '#fff',
                border: 'none',
                borderRadius: '4px',
                cursor: 'pointer',
                fontWeight: 600,
                fontSize: '0.85rem',
              }}
            >
              Search
            </button>
          </div>

          <div style={{ maxHeight: '220px', overflowY: 'auto', border: '1px solid #eee', borderRadius: '4px' }}>
            {availablePlans.length === 0 ? (
              <div style={{ padding: '16px', textAlign: 'center', color: '#6c757d', fontSize: '0.85rem' }}>
                No survey plans found.
              </div>
            ) : (
              availablePlans.map((sp) => (
                <div
                  key={sp.id}
                  style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    padding: '10px 12px',
                    borderBottom: '1px solid #eee',
                  }}
                >
                  <div>
                    <div style={{ fontWeight: 600, fontSize: '0.9rem' }}>
                      {sp.plan_number} <span style={{ fontSize: '0.75rem', color: '#6c757d' }}>({sp.plan_type})</span>
                    </div>
                    <div style={{ fontSize: '0.8rem', color: '#6c757d' }}>
                      Surveyor: {sp.surveyor_name || 'N/A'} • Agency: {sp.approving_agency || 'N/A'}
                    </div>
                  </div>
                  <button
                    type="button"
                    disabled={linking}
                    onClick={() => handleLinkPlan(sp.id)}
                    style={{
                      padding: '4px 12px',
                      backgroundColor: '#198754',
                      color: '#fff',
                      border: 'none',
                      borderRadius: '4px',
                      fontSize: '0.8rem',
                      fontWeight: 600,
                      cursor: 'pointer',
                    }}
                  >
                    {linking ? 'Linking…' : 'Link'}
                  </button>
                </div>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
};
