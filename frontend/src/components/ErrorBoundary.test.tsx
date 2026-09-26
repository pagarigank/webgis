/**
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, fireEvent } from '@testing-library/react';
import { ErrorBoundary } from './ErrorBoundary';

afterEach(cleanup);

function Boom({ shouldThrow = true }: { shouldThrow?: boolean }): JSX.Element {
  if (shouldThrow) throw new Error('revisions is undefined');
  return <p>recovered content</p>;
}

describe('ErrorBoundary', () => {
  it('renders children when nothing throws', () => {
    render(
      <ErrorBoundary label="Technical description">
        <p>tab content</p>
      </ErrorBoundary>,
    );
    expect(screen.getByText('tab content')).toBeTruthy();
    expect(screen.queryByTestId('error-boundary-fallback')).toBeNull();
  });

  // This is the regression that motivated the boundary: an undefined.length in
  // one tab used to unmount the whole editor, losing the tab bar, the workflow
  // action bar, and the unsaved-changes guard along with it.
  it('contains a crash instead of propagating it to the page', () => {
    const onError = vi.fn();
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});

    render(
      <div>
        <p>surrounding page chrome</p>
        <ErrorBoundary label="Technical description" onError={onError}>
          <Boom />
        </ErrorBoundary>
      </div>,
    );

    expect(screen.getByTestId('error-boundary-fallback')).toBeTruthy();
    expect(screen.getByTestId('error-boundary-message').textContent).toContain('revisions is undefined');
    expect(screen.getByText(/Technical description could not be displayed/)).toBeTruthy();
    // The failure is contained: the surrounding page chrome still rendered.
    expect(screen.getByText('surrounding page chrome')).toBeTruthy();
    expect(onError).toHaveBeenCalledOnce();

    spy.mockRestore();
  });

  it('reports the error to the console so field-only failures stay diagnosable', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    render(
      <ErrorBoundary label="Validation">
        <Boom />
      </ErrorBoundary>,
    );
    expect(spy).toHaveBeenCalled();
    spy.mockRestore();
  });

  it('recovers when the user retries', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    let shouldThrow = true;
    const Flaky = (): JSX.Element => <Boom shouldThrow={shouldThrow} />;

    const { rerender } = render(
      <ErrorBoundary>
        <Flaky />
      </ErrorBoundary>,
    );
    expect(screen.getByTestId('error-boundary-fallback')).toBeTruthy();

    shouldThrow = false;
    fireEvent.click(screen.getByRole('button', { name: /try again/i }));
    rerender(
      <ErrorBoundary>
        <Flaky />
      </ErrorBoundary>,
    );

    expect(screen.getByText('recovered content')).toBeTruthy();
    expect(screen.queryByTestId('error-boundary-fallback')).toBeNull();

    spy.mockRestore();
  });
});
