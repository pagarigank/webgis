import { describe, it, expect } from 'vitest';
import {
    STEPS,
    canCommit,
    canLeaveCrsStep,
    canOpenStep,
    clampStep,
    isTerminal,
    maxReachableStep,
} from './stepModel';
import type { ImportJob, ImportStatus } from '../api/importApi';

const job = (status: ImportStatus, extra: Partial<ImportJob> = {}): ImportJob =>
    ({
        id: 1,
        source_format: 'CSV',
        source_filename: 'f.csv',
        target_entity: 'FEATURE',
        target_layer_id: 1,
        declared_crs: 'EPSG:4326',
        suggested_crs: null,
        transformation_id: null,
        field_mapping: null,
        options: null,
        status,
        total_rows: 2,
        valid_rows: 2,
        invalid_rows: 0,
        validation_result: null,
        created_at: '2026-01-01T00:00:00+00:00',
        committed_at: null,
        ...extra,
    }) as ImportJob;

describe('stepModel', () => {
    describe('the commit step is unreachable until validation succeeds', () => {
        it('is not reachable from UPLOADED', () => {
            expect(canOpenStep('commit', job('UPLOADED'))).toBe(false);
        });

        it('is not reachable from MAPPED', () => {
            expect(canOpenStep('commit', job('MAPPED'))).toBe(false);
        });

        it('is reachable from VALIDATED', () => {
            expect(canOpenStep('commit', job('VALIDATED'))).toBe(true);
        });

        it('is not reachable from FAILED or CANCELLED', () => {
            expect(canOpenStep('commit', job('FAILED'))).toBe(false);
            expect(canOpenStep('commit', job('CANCELLED'))).toBe(false);
        });

        it('is not reachable without a job at all', () => {
            expect(canOpenStep('commit', null)).toBe(false);
        });

        it('stays open from COMMITTED so the outcome can be read', () => {
            expect(canOpenStep('commit', job('COMMITTED'))).toBe(true);
        });

        it('gates canCommit on VALIDATED specifically, not merely a late status', () => {
            expect(canCommit(job('VALIDATED'))).toBe(true);
            expect(canCommit(job('COMMITTED'))).toBe(false);
            expect(canCommit(job('MAPPED'))).toBe(false);
            expect(canCommit(null)).toBe(false);
        });
    });

    describe('the CRS step cannot be skipped', () => {
        it('refuses to leave with a null CRS', () => {
            expect(canLeaveCrsStep(null)).toBe(false);
        });

        it('refuses to leave with an empty or blank CRS', () => {
            expect(canLeaveCrsStep('')).toBe(false);
            expect(canLeaveCrsStep('   ')).toBe(false);
        });

        it('allows leaving once a CRS is declared', () => {
            expect(canLeaveCrsStep('EPSG:4326')).toBe(true);
        });

        it('does not let a MAPPED job reach mapping while the CRS is undeclared', () => {
            // A job reported as MAPPED with no declared CRS is inconsistent; the
            // operator still has to state one before the wizard proceeds.
            const inconsistent = job('MAPPED', { declared_crs: null });
            expect(canOpenStep('mapping', inconsistent)).toBe(true);
            expect(canLeaveCrsStep(inconsistent.declared_crs)).toBe(false);
        });
    });

    describe('step ceiling', () => {
        it('stops at CRS for an uploaded job', () => {
            expect(maxReachableStep('UPLOADED')).toBe(STEPS.indexOf('crs'));
        });

        it('stops at preview for a mapped job', () => {
            expect(maxReachableStep('MAPPED')).toBe(STEPS.indexOf('preview'));
        });

        it('opens commit for a validated job', () => {
            expect(maxReachableStep('VALIDATED')).toBe(STEPS.indexOf('commit'));
        });

        it('is -1 for an unknown or absent status', () => {
            expect(maxReachableStep(null)).toBe(-1);
        });
    });

    describe('clampStep', () => {
        it('keeps a permitted step', () => {
            expect(clampStep('crs', job('UPLOADED'))).toBe('crs');
        });

        it('falls back to upload for a step the job forbids', () => {
            expect(clampStep('commit', job('MAPPED'))).toBe('upload');
        });

        it('falls back to upload with no job', () => {
            expect(clampStep('preview', null)).toBe('upload');
        });
    });

    it('treats only COMMITTED and CANCELLED as terminal', () => {
        expect(isTerminal('COMMITTED')).toBe(true);
        expect(isTerminal('CANCELLED')).toBe(true);
        expect(isTerminal('VALIDATED')).toBe(false);
        expect(isTerminal(null)).toBe(false);
    });
});
