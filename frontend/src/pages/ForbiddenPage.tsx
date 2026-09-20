const ForbiddenPage: React.FC = () => (
  <div
    style={{
      height: '100vh',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      fontFamily: 'system-ui, sans-serif',
    }}
  >
    <h1 style={{ margin: 0 }}>403</h1>
    <p style={{ color: '#555' }}>You do not have permission to view this page.</p>
    <a href="/" style={{ color: '#1a5fb4' }}>
      Go home
    </a>
  </div>
);

export default ForbiddenPage;