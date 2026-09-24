// @vitest-environment jsdom
import { render, screen, cleanup, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { ComputationPanel } from './ComputationPanel';

afterEach(cleanup);

vi.mock('../api/surveyApi', () => ({
  surveyApi: {
    listByParcel: vi.fn().mockResolvedValue([
      {
        id: 1,
        parcel_id: 'test-parcel-id',
        revision: 1,
        status: 'CONFIRMED',
        parser_status: 'CONFIRMED',
        is_current: true,
        bearing_reference: 'GRID',
        distance_unit: 'm',
      },
    ]),
    getById: vi.fn().mockResolvedValue({
      data: {
        id: 1,
        revision: 1,
        status: 'CONFIRMED',
        bearing_reference: 'GRID',
        distance_unit: 'm',
        courses: [
          { seq: 1, to_point_label: '2', bearing: { azimuth_dd: 90 }, distance: { meters: 100 } },
          { seq: 2, to_point_label: '3', bearing: { azimuth_dd: 0 }, distance: { meters: 50 } },
          { seq: 3, to_point_label: '4', bearing: { azimuth_dd: 270 }, distance: { meters: 100 } },
          { seq: 4, to_point_label: '1', bearing: { azimuth_dd: 180 }, distance: { meters: 50 } },
        ],
        tie_points: [
          {
            id: 10,
            adhoc_name: 'BLLM 1',
            as_used_easting: 500000,
            as_used_northing: 1000000,
            as_used_status: 'VERIFIED',
          },
        ],
      },
    }),
  },
}));

vi.mock('../api/computationApi', () => ({
  computationApi: {
    listForParcel: vi.fn().mockResolvedValue([
      {
        id: 101,
        parcel_id: 'test-parcel-id',
        technical_description_id: 1,
        compute_crs: 'EPSG:3123',
        method: 'TRAVERSE_PLANE',
        adjustment_method: null,
        base_computation_id: null,
        linear_error_m: 0.002,
        relative_precision: '1:50000',
        computed_area_sqm: 5000,
        postgis_area_sqm: 5000,
        source_area_sqm: 5000,
        closure_status: 'WITHIN_TOLERANCE',
        is_current: true,
        engine_version: '1.0.0',
        computed_at: '2026-09-24T00:00:00Z',
        computed_by_name: 'Admin',
      },
    ]),
    get: vi.fn().mockResolvedValue({
      computation_id: 101,
      engine_version: '1.0.0',
      compute_crs: 'EPSG:3123',
      tie_points: [{ name: 'BLLM 1', as_used_easting: 500000, as_used_northing: 1000000, as_used_status: 'VERIFIED' }],
      vertices: [
        { seq: 1, label: '1', easting: 500000, northing: 1000000, latitude: 14.6, longitude: 121.0, delta_e: 100, delta_n: 0 },
        { seq: 2, label: '2', easting: 500100, northing: 1000000, latitude: 14.6, longitude: 121.001, delta_e: 0, delta_n: 50 },
        { seq: 3, label: '3', easting: 500100, northing: 1000050, latitude: 14.6005, longitude: 121.001, delta_e: -100, delta_n: 0 },
        { seq: 4, label: '4', easting: 500000, northing: 1000050, latitude: 14.6005, longitude: 121.0, delta_e: 0, delta_n: -50 },
      ],
      closure: {
        delta_e: 0.001,
        delta_n: 0.001,
        linear_error_m: 0.0014,
        error_azimuth_dd: 45.0,
        perimeter_m: 300,
        relative_precision: '1:50000',
        relative_precision_denominator: 50000,
        status: 'WITHIN_TOLERANCE',
        max_error_m: 0.1,
        min_precision_denominator: 5000,
      },
      area: {
        computed_sqm: 5000,
        postgis_sqm: 5000,
        source_sqm: 5000,
        difference_sqm: 0,
        difference_pct: 0,
        note: 'Area comparison is a validation aid, not a determination of correctness.',
      },
      warnings: [],
      geometry_preview: null,
      persisted_to_parcel: false,
    }),
  },
}));

describe('ComputationPanel', () => {
  it('renders traverse computation header and controls', async () => {
    render(<ComputationPanel parcelId="test-parcel-id" />);

    await waitFor(() => {
      expect(screen.getByText('Traverse Computation Engine')).toBeDefined();
    });

    expect(screen.getByText('Run Computation')).toBeDefined();
    expect(screen.getByText('Compute CRS (Projected)')).toBeDefined();
  });

  it('renders closure metrics and within tolerance badge', async () => {
    render(<ComputationPanel parcelId="test-parcel-id" />);

    await waitFor(() => {
      expect(screen.getByText('Traverse Closure')).toBeDefined();
    });

    expect(screen.getAllByText('WITHIN_TOLERANCE').length).toBeGreaterThan(0);
    expect(screen.getAllByText('1:50000').length).toBeGreaterThan(0);
  });

  it('renders mandatory area validation aid note', async () => {
    render(<ComputationPanel parcelId="test-parcel-id" />);

    await waitFor(() => {
      expect(screen.getByText(/Area comparison is a validation aid, not a determination of correctness/i)).toBeDefined();
    });
  });
});
